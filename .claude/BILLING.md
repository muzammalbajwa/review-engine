# BILLING — the 7-day free trial + Paddle conversion

Originally built on Stripe (Laravel Cashier), switched to Lemon Squeezy
(Stripe doesn't support Pakistan-domiciled businesses), then switched again
to Paddle (`laravel/cashier-paddle`, the officially maintained package —
git history has the full Lemon Squeezy design if it's ever needed). Each
swap only ever touched the conversion/webhook side — the trial, the 5-state
(now 6-state) machine, and everything sending-access-related has been
untouched across all three processors.

## Pricing

One plan, two billing intervals — replaces the earlier Starter/Growth/Pro
three-tier design:

    Monthly: $20/mo (was $25 — shown struck through in the UI)
    Annual:  $200/yr ("2 months free" — $20 × 10, not a flat 20% off)

`config/plans.php`'s `intervals.monthly.price`/`intervals.annual.price`
point at the two real Paddle Price IDs (one Product, two Prices) — set via
`PADDLE_PRICE_MONTHLY`/`PADDLE_PRICE_ANNUAL`. Neither price should have a
free trial configured on the Paddle side — the trial lives entirely in
`tenants` (see below); a Paddle-side trial would create a second,
conflicting source of truth.

## The trial

7 days, no card required. Set in motion by `POST /onboarding/start-trial`
(onboarding's plan step), tracked entirely on `tenants` — see DATABASE.md:

    tenants.plan               nullable string, validated against config('plans')
    tenants.status             pending | trialing | active | trial_expired | past_due | canceled
    tenants.billing_interval   nullable string: monthly | annual — set only at
                                real conversion, never during the trial
    tenants.trial_started_at   set once, at trial start
    tenants.trial_ends_at      trial_started_at + 7 days

Unlike `plan`/`billing_interval` (app-level validation only), `status` is a
real Postgres CHECK constraint (`tenants_status_check` —
`2026_08_10_090000_add_trial_fields_to_tenants_table.php`'s
`$table->enum(...)`, extended by
`2026_08_24_140000_add_past_due_to_tenants_status_enum.php` to add
`past_due`). Writing a status value outside this list fails at the
database, not just at the app layer — a deliberate stricter guarantee for
the one column every access-control decision in this app keys off.

**The one rule that matters: no Paddle Customer or Subscription is ever
created at trial start.** `POST /onboarding/start-trial` never touches
Paddle at all. The first Paddle API call in a tenant's whole lifecycle
happens at real conversion (`POST /subscribe`), whenever that is — during
the trial, right after it expires, or months later. This is a deliberate
choice, not an oversight: it avoids an orphaned Paddle Customer for every
tenant who signs up and never converts, and it means trial state can never
drift out of sync with a billing object that doesn't exist yet.

`status` transitions:

    pending -----(start-trial)-----> trialing
    trialing ----(trial:expire, past trial_ends_at)----> trial_expired
    trialing OR trial_expired --(checkout completes, subscription.created)--> active
    active --(transaction.payment_failed, or subscription.updated status=past_due)--> past_due
    past_due --(transaction.completed / subscription.updated status=active)--> active
    active OR past_due --(subscription.canceled or paused)--> canceled
    canceled --(subscription reactivated, status=active again)--> active

`active` only ever happens via a Paddle webhook (`PaddleWebhookController`)
— `POST /subscribe` itself never activates a tenant synchronously, see
"Conversion" below. `trial_started_at`/`trial_ends_at` are never cleared on
conversion — they stay as the historical record of when the trial ran.
`billing_interval` is set at the same point `status` flips to `active`.

`canceled` is not a reserved-for-later state — `PaddleWebhookController`
actively maps Paddle's subscription lifecycle onto it. `past_due` is a real
sixth state (not a no-op the way it was under the Lemon Squeezy design) —
see "Webhook → status mapping" below for the exact mapping and why it
doesn't block sending access.

## Expiry

`php artisan trial:expire` (`app/Console/Commands/ExpireStaleTrials.php`),
scheduled daily (`routes/console.php`). Finds every tenant with
`status = 'trialing'` and `trial_ends_at <= now()`, flips each to
`trial_expired`. `status = 'trialing'` already means "no paid subscription
exists" by construction of the state machine above — no separate
Subscription-table check needed. Unchanged by any processor swap.

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
during the trial; they just can't start a new send. Unchanged by any
processor swap.

**`past_due` deliberately does NOT block sending access** — confirmed
product decision (not this app's default assumption): a failed payment
during Paddle's own dunning retry window is temporary and often self-heals
without the tenant doing anything (a card that just expired mid-cycle,
gets auto-updated by the card network, etc.). Blocking on the first failed
attempt would be more aggressive than anything this app has ever done for
a customer who is, in every practical sense, still paying. Access only
actually cuts off once Paddle exhausts dunning and the subscription
genuinely reaches `canceled` — see `Tenant::sendingBlockedReason()`'s own
docblock.

As defense in depth, `drip:release-pending` also skips any tenant whose
`sendingBlocked()` is true (`Tenant::sendingBlocked()`), so a batch of
contacts imported right before expiry doesn't keep trickling out sends for
weeks afterward — those contacts stay `status = 'pending'`, untouched,
until the tenant converts.

The frontend shows a persistent banner (`components/AppShell.tsx`'s
`TrialExpiredBanner`, driven by fetching `GET /tenant`) on every
authenticated screen, linking straight to Settings → Billing
(`/settings?tab=billing`).

## Conversion

`POST /subscribe {interval: "monthly"|"annual"}` (`SubscriptionController::
subscribe`, owner-only) is the only entry point — reachable from a
`trialing` or `trial_expired` tenant converting, or a tenant skipping the
trial entirely to pay immediately from onboarding. No dedicated frontend
`SubscribeForm`/checkout UI exists yet — the backend contract is built and
tested; the Paddle.js overlay integration on the frontend is a follow-up.

**Paddle's overlay checkout, not a hosted-page redirect.** Paddle has no
server-side "charge this payment method now" API any more than Lemon
Squeezy did, but its integration shape differs from Lemon Squeezy's:
Cashier's `Billable::checkout()` creates a real Paddle Customer
*synchronously*, in the same request, before any checkout UI even opens —
see "How the webhook knows which tenant" below for why that matters. So:

1. `POST /subscribe` calls `$user->checkout($price)->customData([...])`.
   `checkout()`'s first step (`createAsCustomer()`) is a real, synchronous
   Paddle API call — it looks up or creates a Paddle Customer for this
   user and saves a local `customers` row (tenant-scoped, RLS-protected)
   *before* returning. **This does not activate the tenant.** No payment
   has happened yet.
2. The response is `Checkout::options()` — the exact JSON shape
   `Paddle.Checkout.open(options)` (Paddle.js, loaded client-side with
   `PADDLE_CLIENT_SIDE_TOKEN`) expects: `items` (the resolved price ID +
   quantity), `customer.id` (the Paddle Customer just created), and
   `customData` (`subscription_type` + `tenant_id`). The frontend opens
   Paddle's overlay with this payload; the customer pays inside it,
   without leaving the app.
3. Paddle sends a `subscription.created` webhook once the checkout
   completes. `PaddleWebhookController` is what actually sets
   `tenant.status = 'active'` and `tenant.billing_interval`.

Practical effect: there's a real (usually sub-second, but not guaranteed)
gap between "customer finishes paying" and "tenant.status reads active."
`GET /subscription` reflects whatever the database currently says on
read — no polling loop exists for this; a manual refresh after the overlay
closes resolves it, same as any other eventually-consistent webhook-driven
state in this app.

### How the webhook knows which tenant

A Paddle webhook carries no Sanctum bearer token — it's an unauthenticated
POST from Paddle's servers. `SetTenantContext` middleware does nothing for
it, so `app.current_tenant_id` would otherwise be unset, and every
cashier-paddle table's `FORCE ROW LEVEL SECURITY` policy means the
webhook's writes would silently match zero rows.

Unlike the Lemon Squeezy design (which had no other option — its checkout
never created a local row before the webhook arrived, so it had to resolve
tenant context from `custom_data` alone), Paddle's checkout flow already
created a local `customers` row *synchronously*, at step 1 above, with the
correct `tenant_id` already stamped via `BelongsToTenant`. Every Paddle
webhook this app handles carries a `customer_id` (or, for
`customer.updated`, is itself keyed by that id) — `PaddleWebhookController`
resolves `tenant_id` by looking that row up (bypassing RLS the same narrow,
scoped-to-one-query way `SetTenantContext`'s own token lookup does), not
from `custom_data`. `custom_data.tenant_id` (also set at checkout time via
`customData()`) is still passed through and cross-checked as defense in
depth — a mismatch is logged loudly, never silently trusted either way
(.claude/SECURITY.md #1).

`PaddleWebhookController` extends (not wraps — Cashier's own
`WebhookController` isn't `final`, unlike Lemon Squeezy's) the package's
controller: it resolves tenant context, sets `app.current_tenant_id` +
`CurrentTenant`, *then* calls `parent::__invoke()` to run Cashier's own
event dispatch, then syncs `tenant.status` once that call confirms success.

## Webhook → status mapping

Paddle's subscription status vocabulary
(`Laravel\Paddle\Subscription::STATUS_*`) maps onto this app's state
machine via `PaddleWebhookController::STATUS_MAP`, read fresh from the
`subscriptions` row after Cashier's own handler has already written it:

| Paddle `status` | `tenant.status` | Why |
|---|---|---|
| `active` | `active` | Paying, in good standing. |
| `past_due` | `past_due` | Dunning in progress — see below. |
| `paused` | `canceled` | Functionally the same as canceled for sending-access purposes — no separate `paused` slot in the state machine. |
| `canceled` | `canceled` | The subscription has genuinely ended. |
| `trialing` | *(no entry — no-op)* | Should never actually occur (see "Pricing" above: no Paddle-side trial configured). If it ever does, having no mapping is the safe default — never silently grant `active` for a status that isn't really "paying." |

Unlike the Lemon Squeezy design, this needs **no `ends_at`-based "is this
actually still in its grace period" heuristic**
(`mappedStatusForCancelled()` there). Paddle's own `status` field already
stays `active` through a scheduled cancel-at-period-end's notice window
(`data.scheduled_change`, not a status change) and only flips to
`canceled` once the subscription actually ends — the status column, read
directly, is sufficient.

`transaction.payment_failed` is handled separately, NOT through this
table: Cashier's base `WebhookController` has no handler for this event at
all (verified against the installed package — it silently no-ops).
`PaddleWebhookController::handleTransactionPaymentFailed()` is a real
override that sets `tenant.status = 'past_due'` directly, immediately, on
the first failed transaction — independent of whether Paddle has updated
the subscription's own `status` column yet (a single failed attempt
doesn't necessarily mean it has). This is deliberately excluded from the
generic status-sync path above: reading the subscription's still-`active`
status on that same event would otherwise silently clobber the `past_due`
signal right back (a real bug this app's own tests caught and fixed, not
a hypothetical). The write only ever fires from `active`/`past_due` —
never pulls an already-`canceled`/`trial_expired` tenant back into
`past_due` from a stray, late transaction retry against a subscription
that has already really ended.

`transaction.completed` also re-syncs status via the same generic path
(reading the subscription's current status) — this covers "recovered from
`past_due` once a retried transaction succeeds" even if a
`subscription.updated` event hasn't landed yet.

`billing_interval` is synced independently, whenever a webhook's
`items[].price.id` matches one of `config('plans.standard.intervals').*.price`
— covers a plan/interval swap via `subscription.updated`, not just
creation.

## Auto-renew toggle

`PATCH /subscription {auto_renew: bool}` (`SubscriptionController::update`,
owner-only like the rest of billing). `renews_at` is **not** a Cashier
Paddle column — the package only ever reads Paddle's `next_billed_at` to
set `trial_ends_at` while trialing, then discards it
(`2026_08_24_150000_add_renewal_tracking_to_subscriptions_table.php`
confirmed this against the installed source before adding the column).
`PaddleWebhookController::syncRenewalDate()` writes it on every
`subscription.created`/`updated`, straight from `data.next_billed_at`.
`App\Models\Subscription::autoRenews()`/`periodEnd()` derive the toggle
state and the date to show from `ends_at`/`renews_at`/`paused()`/
`canceled()` — same shape the previous processor's identically-named
methods used, against genuinely different underlying facts.

- `auto_renew: false` calls **`Subscription::cancel(false)`** — Paddle's
  real cancel-at-period-end (`POST /subscriptions/{id}/cancel`,
  `effective_from: 'next_billing_period'`, confirmed against the
  installed Cashier source). This is NOT an immediate cancellation:
  `status` stays whatever it already is (`active`) through the notice
  window — Paddle writes `ends_at` to the scheduled effective date, not
  `status`. The tenant keeps full access until the real
  `subscription.canceled` webhook fires once that date arrives.
- `auto_renew: true` calls **`Subscription::stopCancelation()`** — the
  real "undo a scheduled cancellation" method (`PATCH /subscriptions/{id}`
  with `scheduled_change: null`). **Deliberately not `resume()`**:
  `resume()` is Paddle's *pause*-undo mechanism, a completely different
  endpoint with its own semantics — verified against the installed
  package source before writing this, not assumed from the previous
  processor's single "resume" concept, which doesn't map onto Paddle's
  shape. A paused or already-canceled subscription has no scheduled
  cancellation to undo — those get a clean 422 (`subscription_ended`)
  instead of a wrong API call, checked client-side (`paused()`/
  `canceled()`) before ever calling Paddle.

Both directions are idempotent against the subscription's *current*
Paddle state (`autoRenews()`) rather than always placing an outbound
call — toggling to the state it's already in never hits Paddle at all
(`tests/Feature/Billing/AutoRenewToggleTest.php` asserts this with
`Http::assertNothingSent()`).

## Renewal reminders

`billing:send-renewal-reminders` (`app/Console/Commands/
SendRenewalReminders.php`), scheduled daily (`routes/console.php`) — same
shape as `trial:expire`: a bulk, RLS-bypassed read of candidate
subscription ids, then a per-row re-verify-and-write inside that row's own
tenant context. At exactly 10 and 5 days before a subscription's
`current_period_end` (`App\Models\Subscription::periodEnd()`), every
tenant owner is emailed (`App\Notifications\SubscriptionRenewalReminder`,
unchanged across every processor this app has had — it takes only
primitives) — a courtesy notice while `autoRenews()` is true, an
action-needed notice once it's false. Idempotency is a nullable `date`
column per threshold (`renewal_reminder_{10,5}d_sent_for`), same reasoning
as `trial:expire`'s own docblock for why a plain `lockForUpdate()` — not a
`Cache::lock` — is the right amount of machinery for this command's
narrow, low-contention race.

`TenantController::renewalReminder()` (`GET /tenant`'s `renewal_reminder`
field, driving the dashboard banner) is live-computed from the same
`periodEnd()`/`autoRenews()`, independent of whether the email above has
actually sent — same "state-driven, not event-driven" design
`TrialExpiredBanner` already uses.

**Customer portal (`GET /subscription/portal`) — still not rebuilt.**
Recoverable from git history if it's ever needed; out of scope for this
pass.

## Existing tenants on prior pricing/processors

Checked directly (`php artisan tinker`/`psql` against this environment's
own DB) before each processor-swap migration that dropped the previous
processor's schema: **zero tenants exist anywhere with a non-null `plan`**
in this local/test environment, so there was no live data any of these
touched. Nothing here is a live migration that ran against real
subscribers.

If a real environment (staging or later production) ever does have tenants
on a prior processor/pricing tier when a future swap ships:

- **`tenant.plan`/`tenant.status` are never silently rewritten.** Nothing
  in a processor swap touches an existing tenant row —
  `plan`/`status`/`billing_interval` only ever change via
  `Tenant::startTrial()` (trial start) or a real webhook event
  (conversion/cancellation/resumption).
- **A real subscription on the prior processor would need to be handled
  separately, outside this app's code**, before that processor's schema
  is dropped for real (every drop migration in this app's history already
  ran here because there was nothing to preserve) — either migrate
  subscribers to the new processor's checkout manually, or keep charging
  them through the old processor on a legacy path until they churn or are
  moved over deliberately. This app has no automatic cross-processor
  subscription migration tool; building one is out of scope for a schema
  swap and needs its own explicit decision before any environment with
  real subscribers runs these migrations.

## Test-mode Paddle credentials

`config/plans.php`'s `PADDLE_PRICE_MONTHLY`/`PADDLE_PRICE_ANNUAL` need to
point at real Paddle sandbox Price objects (one Product, two Prices — $20/mo
and $200/yr), and `PADDLE_SELLER_ID`/`PADDLE_AUTH_CODE`/
`PADDLE_CLIENT_SIDE_TOKEN`/`PADDLE_WEBHOOK_SECRET` need real sandbox
values, before the conversion flow can call Paddle's real sandbox API
end-to-end (`PADDLE_SANDBOX=true` — `Cashier::apiUrl()` resolves to
`sandbox-api.paddle.com`).

`tests/Feature/Billing/SubscribeCheckoutTest.php`'s checkout tests don't
need real sandbox credentials to run and pass — they use `Http::fake()`
to stand in for Paddle's own API, proving the request/response shape this
app builds without a live network call.
`tests/Feature/Billing/PaddleWebhookSignatureTest.php`/
`PaddleWebhookStatusTest.php` similarly prove signature verification and
the full event → `tenant.status` mapping (including `past_due`) using
Cashier's real `VerifyWebhookSignature` middleware and realistic Paddle
payload shapes, all without needing Paddle's live infrastructure. What
none of this proves is a webhook actually delivered by Paddle's real
servers over the public internet, or a checkout completed through Paddle's
real overlay UI in a browser — that needs real sandbox credentials, a
publicly reachable webhook URL (a tunnel in local dev), and either a human
completing a sandbox checkout or driving Paddle's sandbox API directly.
