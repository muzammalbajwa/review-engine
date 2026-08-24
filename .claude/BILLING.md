# BILLING — the 7-day free trial + Lemon Squeezy conversion

This file didn't exist before this feature — `config/plans.php` already had a
comment noting the gap. This is the trial/subscription design going forward.

Originally built on Stripe (Laravel Cashier); switched to Lemon Squeezy
because Stripe doesn't support Pakistan-domiciled businesses at all — not a
config change, a real swap of the billing layer (processor, webhook signing
scheme, and the conversion flow's whole shape, since Lemon Squeezy has no
server-side "charge this payment method now" API the way Stripe did — see
"Conversion" below).

## Pricing

One plan, two billing intervals — replaces the earlier Starter/Growth/Pro
three-tier design:

    Monthly: $20/mo (was $25 — shown struck through in the UI)
    Annual:  $200/yr ("2 months free" — $20 × 10, not a flat 20% off)

`config/plans.php`'s `intervals.monthly.variant`/`intervals.annual.variant`
point at the two real Lemon Squeezy Variant IDs (one product, two variants)
— set via `LEMON_SQUEEZY_VARIANT_MONTHLY`/`LEMON_SQUEEZY_VARIANT_ANNUAL`.
Neither variant should have a free trial configured on the Lemon Squeezy
side — the trial lives entirely in `tenants` (see below); a Lemon Squeezy
variant-level trial would create a second, conflicting source of truth.

## The trial

7 days, no card required. Set in motion by `POST /onboarding/start-trial`
(onboarding's plan step), tracked entirely on `tenants` — see DATABASE.md:

    tenants.plan               nullable string, validated against config('plans')
    tenants.status             pending | trialing | active | trial_expired | canceled
    tenants.billing_interval   nullable string: monthly | annual — set only at
                                real conversion, never during the trial
    tenants.trial_started_at   set once, at trial start
    tenants.trial_ends_at      trial_started_at + 7 days

**The one rule that matters: no Lemon Squeezy Customer or Subscription is
ever created at trial start.** `POST /onboarding/start-trial` never touches
Lemon Squeezy at all. The first Lemon Squeezy API call in a tenant's whole
lifecycle happens at real conversion (`POST /subscribe`), whenever that is —
during the trial, right after it expires, or months later. This is a
deliberate choice, not an oversight: it avoids an orphaned Lemon Squeezy
Customer for every tenant who signs up and never converts, and it means
trial state can never drift out of sync with a billing object that doesn't
exist yet. (Unchanged from the Stripe design — this is exactly why the swap
below only ever touches the conversion side.)

`status` transitions:

    pending -----(start-trial)-----> trialing
    trialing ----(trial:expire, past trial_ends_at)----> trial_expired
    trialing OR trial_expired --(checkout completes, webhook)--> active
    active OR trialing OR trial_expired --(cancelled/expired/paused webhook)--> canceled
    canceled --(resumed/unpaused webhook)--> active

`active` only ever happens via a Lemon Squeezy webhook now (see
"Conversion" — this is the one real behavior change from the Stripe design,
which flipped `active` synchronously inside `POST /subscribe` itself).
`trial_started_at`/`trial_ends_at` are never cleared on conversion — they
stay as the historical record of when the trial ran. `billing_interval` is
set at the same point `status` flips to `active`.

`canceled` is no longer a reserved-for-later state — `LemonSqueezyWebhookController`
actively maps Lemon Squeezy's subscription lifecycle onto it. See "Webhook →
status mapping" below for the exact table and the two statuses (`past_due`,
`unpaid`) that deliberately do NOT touch `tenant.status`.

## Expiry

`php artisan trial:expire` (`app/Console/Commands/ExpireStaleTrials.php`),
scheduled daily (`routes/console.php`). Finds every tenant with
`status = 'trialing'` and `trial_ends_at <= now()`, flips each to
`trial_expired`. `status = 'trialing'` already means "no paid subscription
exists" by construction of the state machine above — no separate
Subscription-table check needed. Unchanged by the Lemon Squeezy swap.

**This command only ever writes `tenants.status`.** It must never touch
contacts, campaigns, templates, or messages — see
`tests/Feature/Billing/TrialExpiryDataIntegrityTest.php`'s CRITICAL test,
which snapshots every row on a tenant before and after expiry and asserts
byte-for-byte equality.

## What trial_expired actually blocks

`app/Http/Middleware/RequireSendingAccess.php` (alias `sending.access`),
applied to exactly four routes — every entry point that creates a NEW
outbound review request:

- `POST /contacts/import` (CSV import)
- `POST /contacts/quick-add` (authenticated quick-add)
- `POST /quick/{token}` (public guest quick-add link)
- `POST /contacts` (the webhook API)

Everything else — dashboard, GET /contacts, GET /templates, GET /reviews,
GET /analytics/campaign, template editing, review replies, GBP connection —
stays fully available. A trial_expired tenant can see everything they built
during the trial; they just can't start a new send. Unchanged by the Lemon
Squeezy swap.

As defense in depth, `drip:release-pending` also skips any tenant whose
`sendingBlocked()` is true (`Tenant::sendingBlocked()`), so a batch of
contacts imported right before expiry doesn't keep trickling out sends for
weeks afterward — those contacts stay `status = 'pending'`, untouched,
until the tenant converts.

The frontend shows a persistent banner (`components/SidebarShell.tsx`'s
`TrialExpiredBanner`, driven by `AppShell` fetching `GET /tenant`) on every
authenticated screen, linking straight to Settings → Billing
(`/settings?tab=billing`).

## Conversion

`Settings → Billing` (`app/(app)/settings/SubscribeForm.tsx`) is the only
place a card is ever collected outside of nothing — onboarding's plan step
(`PlanSelector.tsx`) has no card field at all, and does not offer a
monthly/annual choice either (the interval decision is deferred to
conversion — onboarding just previews the new $25→$20 pricing and starts
the trial). Both `SubscribeForm.tsx` and a tenant skipping the trial
entirely to pay immediately hit the same `POST /subscribe`.

**This is the one real architectural difference from the old Stripe
design.** Lemon Squeezy is a merchant of record with no server-side "charge
this payment method now" API — every transaction goes through their hosted
Checkout (redirect or embedded overlay), never a raw PaymentMethod token
posted to our own backend the way Stripe Elements' `CardElement` worked.
So:

1. `POST /subscribe {interval: "monthly"|"annual"}` creates a Lemon Squeezy
   Checkout for the resolved variant (`SubscriptionController::subscribe`)
   and returns its URL. **This does not activate the tenant.** No card has
   been charged yet.
2. The frontend redirects the browser to that URL. The customer pays on
   Lemon Squeezy's own hosted page.
3. Lemon Squeezy sends a `subscription_created` webhook (and Lemon Squeezy
   redirects the browser back to `/settings`) once the checkout completes.
   `LemonSqueezyWebhookController` is what actually sets
   `tenant.status = 'active'` and `tenant.billing_interval`.

Practical effect: there's a real (usually sub-second, but not guaranteed)
gap between "customer finishes paying" and "tenant.status reads active."
`/settings` reflects whatever `GET /subscription` currently says on
render/refresh — no polling loop was added for this; a manual refresh
after redirect-back resolves it same as any other eventually-consistent
webhook-driven state in this app.

### custom_data.tenant_id — how the webhook knows which tenant

A Lemon Squeezy webhook carries no Sanctum bearer token — it's an
unauthenticated POST from Lemon Squeezy's servers. `SetTenantContext`
middleware does nothing for it, so `app.current_tenant_id` would otherwise
be unset, and every tenant table's `FORCE ROW LEVEL SECURITY` policy means
the webhook's writes would silently match zero rows.

Fixed the same way `custom_data` is designed to be used
(docs.lemonsqueezy.com/help/checkout/passing-custom-data): `subscribe()`
passes `custom: ['tenant_id' => $tenant->id]` at checkout time, and Lemon
Squeezy round-trips that value into `meta.custom_data.tenant_id` on every
Order/Subscription/License-key webhook tied to that checkout — not just
the first one, so this resolves correctly for `subscription_updated`/
`cancelled`/`resumed`/`expired`/`paused`/`unpaused` too.
`LemonSqueezyWebhookController` reads it, calls the same `set_config()` +
`CurrentTenant::set()` pair `SetTenantContext` uses for a normal request,
*then* delegates to `lemonsqueezy/laravel`'s own webhook processing (its
`WebhookController` is `final`, so this wraps rather than extends it).

## Webhook → status mapping

Lemon Squeezy's subscription statuses are richer than this app's 5-state
machine — the mapping below (`LemonSqueezyWebhookController::STATUS_MAP`)
collapses them onto the existing states rather than inventing new ones,
keyed off `data.attributes.status` (present on every subscription webhook,
not just `subscription_created`) rather than `meta.event_name`, so it
covers the full lifecycle uniformly:

| Lemon Squeezy `status` | `tenant.status` | Why |
|---|---|---|
| `active` | `active` | Paying, in good standing. |
| `cancelled`, `ends_at` in the future | *(no-op)* | Cancel-at-period-end has been requested (Settings/Billing's auto-renew toggle, or a cancellation via Lemon Squeezy's own customer portal) but the current billing period hasn't ended yet — see "Auto-renew toggle" below. `tenant.status` stays whatever it already is (`active`); cutting access here would defeat the entire point of cancel-at-period-end. |
| `cancelled`, `ends_at` null or already past | `canceled` | Not a normal cancel-at-period-end shape — treated as an immediate cancellation rather than silently granting extra access. |
| `expired` | `canceled` | The grace period genuinely ended (or dunning was exhausted with no grace period to begin with). This is the real trigger that revokes access for a non-renewing subscription — not a timestamp comparison our own code runs, but Lemon Squeezy's own webhook firing when `ends_at` arrives. |
| `paused` | `canceled` | Functionally the same as canceled for sending-access purposes — no `paused` slot exists in the 5-state machine. |
| `past_due` | *(no-op)* | A failed renewal starts Lemon Squeezy's own dunning retry window. Blocking sending on the first failed attempt would be more aggressive than anything this app has ever done — dunning/payment-failure handling was never built for Stripe either. Confirmed decision, not an oversight. |
| `unpaid` | *(no-op)* | Same reasoning as `past_due`. |
| `on_trial` | *(no-op)* | Should never actually occur — see "Pricing" above (no variant-level trial). If it ever does, staying a no-op is the safe default: never silently grant `active` for a status that isn't really "paying." |

`unpause`/`resume` aren't separate branches in this table — Lemon Squeezy
reports the subscription's status as `active` again once resumed/unpaused,
so the same `active` row handles it without a name-per-event special case.

`billing_interval` is synced independently, whenever a webhook's
`variant_id` matches one of `config('plans.intervals').*.variant` — covers
a `subscription_updated` from a plan/interval swap, not just creation.

## Auto-renew toggle

Settings/Billing (`BillingSection.tsx`) shows and controls whether a real,
active Lemon Squeezy subscription will renew — "Renews automatically on
[date]" vs "Ends on [date] — renew manually to keep access." No new
column anywhere: `lemon_squeezy_subscriptions` (`lemonsqueezy/laravel`'s
own table, already synced via webhook — see "Webhook → status mapping"
above) already carries everything this needs in `status`/`renews_at`/
`ends_at`. `App\Models\Subscription::autoRenews()`/`periodEnd()` derive
the toggle state and the date to show from those three columns; adding a
stored `auto_renew` boolean would just be a second, driftable copy of
`status !== 'cancelled'`.

`PATCH /subscription {auto_renew: bool}` (`SubscriptionController::update`,
owner-only like the rest of billing):

- `auto_renew: false` calls `Subscription::cancel()` — a Lemon Squeezy
  `DELETE /subscriptions/{id}` call. This is Lemon Squeezy's actual
  cancel-at-period-end: it does not revoke access immediately. Lemon
  Squeezy sets the subscription's `status` to `cancelled` and `ends_at` to
  the end of the already-paid-for billing period; the tenant keeps full
  access until then.
- `auto_renew: true` calls `Subscription::resume()` — a
  `PATCH /subscriptions/{id}` with `cancelled: false`. This is Lemon
  Squeezy's real resume mechanism (confirmed against `lemonsqueezy/laravel`
  itself, not assumed from Stripe's `->resume()`, which works differently).
  The package's own `resume()` throws if the subscription is already
  `expired` — Lemon Squeezy has no "resume after the grace period ended"
  operation, so `SubscriptionController::update` turns that into a clean
  422 (`subscription_ended`), not a 500.

Both directions are idempotent against the subscription's *current* Lemon
Squeezy state (`autoRenews()`) rather than always placing an outbound
call — toggling to the state it's already in never hits Lemon Squeezy at
all, so it can't fail even without a working API key.

**The bug this feature exposed and fixed**: before this, the webhook
mapping above collapsed `cancelled` straight to `tenant.status = 'canceled'`
regardless of `ends_at`, which would have cut off access the instant
cancel-at-period-end was requested — exactly the immediate-cancellation
behavior this feature exists to NOT have.
`LemonSqueezyWebhookController::mappedStatusForCancelled()` is the fix:
a `cancelled` webhook with a future `ends_at` is now a no-op, and the
real access-revoking trigger is the `expired` webhook Lemon Squeezy sends
once that date actually arrives.

`Tenant::sendingBlocked()` (`RequireSendingAccess`, `drip:release-pending`'s
defense-in-depth check) now blocks `status = 'canceled'` in addition to
`trial_expired` — same "access ends, data doesn't" logic
(`TrialExpiryDataIntegrityTest.php`'s CRITICAL guarantee applies here too;
nothing about this feature touches contacts/campaigns/templates/messages),
just reached by a subscription lapsing instead of a trial running out.
`RequireSendingAccess` returns `error: 'subscription_ended'` (not
`trial_expired`) when that's the actual reason, so the frontend/API
consumer sees an accurate error code rather than trial-specific copy for
a tenant who was actually a paying customer.

## Renewal reminders

`billing:send-renewal-reminders` (`app/Console/Commands/SendRenewalReminders.php`),
scheduled daily (`routes/console.php`) — same shape as `trial:expire`: a
bulk, RLS-bypassed read of candidate subscription ids, then a per-row
re-verify-and-write inside that row's own tenant context. At exactly 10
and 5 days before a subscription's `current_period_end`
(`App\Models\Subscription::periodEnd()`), every tenant owner is emailed
(`App\Notifications\SubscriptionRenewalReminder`) — a courtesy notice
("your card will be charged $X on [date]") while `autoRenews()` is true,
an action-needed notice ("renew now to keep sending") once it's false
(cancel-at-period-end already requested).

**Idempotency: a nullable `date` column per threshold**
(`lemon_squeezy_subscriptions.renewal_reminder_{10,5}d_sent_for`), not a
`Cache::lock`, and not a plain boolean either. Two reasons this shape,
not the other two options mentioned when this was scoped:
- **Why not `trial:expire`'s "no lock needed, the write is idempotent"
  reasoning verbatim**: that write is a pure status flip — redoing it
  twice is a true no-op. This command's side effect (a Notification
  send) is NOT idempotent by nature — sending it twice is a real
  duplicate email, the same category of problem `drip:release-pending`'s
  `Cache::lock` exists to prevent for double-dispatching
  `SendReviewRequest`. So this needed *some* guard trial:expire doesn't.
- **Why not `drip:release-pending`'s `Cache::lock`**: that lock protects
  a *batch selection* race across many contact rows per tenant, at real
  contention (a documented, previously-reproduced concurrency bug). This
  command's race is narrower — one subscription row's own read-then-write
  — and the realistic collision (a manual `php artisan
  billing:send-renewal-reminders` run landing on the exact day a
  scheduled tick also fires) is rare and low-stakes (one duplicate
  reminder email, not a duplicate customer-facing send). A plain
  `lockForUpdate()` inside the per-row transaction that's already being
  opened closes the race completely — two concurrent transactions
  serialize on that row at the Postgres level — without reaching for
  Redis-backed `Cache::lock`/`LockTimeoutException` handling sized for a
  higher-contention problem this command doesn't have.
- **Why not a plain boolean/timestamp `sent_at`** (`revoked_alert_sent_at`'s
  own shape): `current_period_end` moves forward every billing cycle
  (`renews_at`/`ends_at` update via the normal webhook flow), and a
  reminder must re-fire each cycle, not just once ever. Storing the
  *date the reminder was sent for* — compared against the freshly
  recomputed `periodEnd()` on every run — makes next cycle's reminder
  fire correctly with no explicit reset hook anywhere (contrast
  `revoked_alert_sent_at`, which needs `GbpController::callback()` to
  explicitly clear it on reconnect, because that flag has no "which
  cycle" dimension to compare against on its own).

**The dashboard banner** (`SidebarShell.tsx`'s `RenewalReminderBanner`,
driven by `GET /tenant`'s `renewal_reminder` field,
`TenantController::renewalReminder()`) is deliberately NOT tied to
whether the email above has actually been sent — it's live-computed from
current subscription state on every `/tenant` fetch, the same "state-driven,
not event-driven" design `TrialExpiredBanner` already uses. It also uses a
wider window than the email (any time `current_period_end` is within 10
days, not just exactly the 10-day/5-day marks) so it stays visible
"from login" on every day in between, not just flashing on the two exact
threshold days the email fires on.

## Existing tenants on the old Stripe pricing

Checked directly (`php artisan tinker` against this environment's own DB)
before writing the migration that drops Cashier's schema: **zero tenants
exist anywhere with a non-null `plan`** in this local/test environment, so
there was no live data this touched. Nothing here is a live migration that
ran against real subscribers.

If a real environment (staging or later production) ever does have tenants
on the old Starter/Growth/Pro tiers when this ships:

- **`tenant.plan`/`tenant.status` are never silently rewritten.** Nothing
  in this change touches an existing tenant row — `plan`/`status`/
  `billing_interval` only ever change via `Tenant::startTrial()` (trial
  start) or a real webhook event (conversion/cancellation/resumption).
  An already-`active` tenant with `plan = 'growth'` (a key that no longer
  exists in `config('plans')`) stays exactly `active`/`growth` until
  something *they* do changes it.
- **Their real Stripe subscription would need to be handled separately,
  outside this app's code**, before `laravel/cashier` and the `subscriptions`
  table are dropped for real (this migration already ran here because
  there was nothing to preserve) — either migrate them to Lemon Squeezy
  checkouts manually, or keep charging them through Stripe on a legacy
  path until they churn or are moved over deliberately. This app has no
  automatic Stripe→Lemon Squeezy subscription migration tool; building one
  was out of scope for this change and would need its own explicit
  decision before any environment with real subscribers runs these
  migrations.
- `SubscriptionController::show`'s payload returns whatever `tenant.plan`
  actually is, even an old three-tier key the frontend no longer has a
  display name for — `BillingSection.tsx` falls back to showing the raw
  key rather than crashing (see that component's `PLAN_NAMES` lookup).

## Test-mode Lemon Squeezy objects

`config/plans.php`'s `LEMON_SQUEEZY_VARIANT_MONTHLY`/`LEMON_SQUEEZY_VARIANT_ANNUAL`
need to point at real Lemon Squeezy test-mode Variant objects (one product,
two variants — $20/mo and $200/yr), and `LEMON_SQUEEZY_API_KEY`/
`LEMON_SQUEEZY_STORE`/`LEMON_SQUEEZY_SIGNING_SECRET` need real test-mode
values, before the conversion flow can actually call Lemon Squeezy
successfully — in manual testing, in `tests/Feature/Billing/TrialConversionTest.php`
(a real checkout-URL creation call), and in
`tests/Feature/Billing/LemonSqueezyWebhookSignatureTest.php` (signature
verification only needs a signing secret, not a full API key — that one
runs regardless). Until real credentials exist, checkout-creation and
customer-portal tests are `markTestSkipped`, same pattern the old Stripe
tests used for a missing `cashier.secret`.
