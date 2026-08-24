# API — the contract between Next.js and Laravel

## Rules
- Next.js calls Laravel over HTTPS. Laravel is the only DB access.
- All routes (except login/register/lemon-squeezy-webhook) require Sanctum auth.
- Every route: Form Request validation → Policy authorization → controller.
- Consistent JSON: { data } on success, { error, message, fields } on failure.
- Version the API: /api/v1/...

## Endpoint groups
Auth:        POST /register, /login, /logout
             GET /email/verify/{id}/{hash} (signed, public — clicked from
             the verification email), POST /email/verification-notification
             ("Resend verification email" — authenticated) — see AUTH.md
Tenant:      GET/PATCH /tenant (self) — GET also carries renewal_reminder
             (null unless status=active and current_period_end is within
             10 days; see BILLING.md's "Renewal reminders")
Subscription:POST /subscribe (interval: monthly|annual — returns a Lemon
             Squeezy checkout URL, does not activate synchronously),
             GET /subscription, PATCH /subscription (auto_renew: bool —
             the Settings/Billing auto-renew toggle; cancel-at-period-end
             or resume, see BILLING.md), GET /subscription/portal,
             POST /lemon-squeezy/webhook
Onboarding: ...POST /onboarding/start-trial (plan) — starts the 7-day free
             trial, no card, no Lemon Squeezy call (see BILLING.md). 422
             trial_already_started if the tenant already picked a plan.
GBP:         GET /gbp/connect (OAuth start), GET /gbp/callback, GET /reviews
Campaigns:   CRUD /campaigns
Templates:   CRUD /templates  (save triggers compliance check → may 422)
Contacts:    POST /contacts/import (CSV), GET /contacts, POST /contacts/quick-add,
             GET /contacts/webhook-activity (count + last 5, source=webhook,
             trailing 7 days — Settings' "is this working" panel; excludes
             the Settings test-event button's own contacts, see below)
Quick-add:   GET/POST /quick/{tenant_token}  (public, rate-limited, no auth)
API keys:    GET/POST /api-keys  (dashboard-session auth; issues/rotates webhook
             API keys — POST's response includes `graced_keys`, the real
             expires_at just written for any key(s) it just grace-perioded)
Reviews:     GET /reviews, POST /reviews/{id}/reply
Tours:       GET /tours/status, POST /tours/welcome/complete,
             POST /tours/screens/{key}/complete — product-tour progress,
             per-user (see DATABASE.md's users table row). {key} is a
             route-level format constraint ([a-z0-9_-]{1,40}), not an
             enum — no per-screen tour content exists yet.
Admin:       GET /admin/tenants, GET /admin/tenants/{id}/*  (logged, admin only)

## Public webhook API (POST /api/v1/contacts)
A first-class, versioned integration surface — not a dashboard endpoint. Same
rigor as the Lemon Squeezy webhook handling.
- **Auth**: `Authorization: Bearer <api_key>`, a Sanctum personal access token
  scoped to the `contacts:create` ability only (issued via POST /api-keys).
  Invalid/missing/expired key → 401. Valid key, wrong ability → 403.
- **Versioning**: the /v1/ route prefix AND the controller's own `Api\V1`
  namespace — a v2 with different behavior would live in a separate
  `Api\V2\...` controller under its own `Route::prefix('v2')`, never
  touching v1.
- **Idempotency**: optional `external_id` in the payload. A repeat POST with
  the same `external_id` within 24h returns the *original* contact (200), not
  a new one (201) and not an error — standard idempotent-API behavior.
  Dedup is windowed, not permanent: the same `external_id` after 24h creates
  a new contact.
- **Rate limits**: per-tenant, tied to the subscription plan
  (`config/plans.php` `requests_per_minute` — unsubscribed tenants fall back
  to the starter tier's figure). 429 responses carry a `Retry-After` header.
- **Key rotation**: issuing a new key (POST /api-keys) gives any existing
  key a 24h grace period (`expires_at`) rather than deleting it instantly —
  both keys work during that window.
- Creates a Contact with `source = 'webhook'`, the same enrollment path
  (campaign, status) CSV import and quick-add both use — no special casing.
- Settings' "Send test event" button calls this exact endpoint (with the
  tenant's own dashboard session token, which the ability check already
  allows through — see above) rather than a separate test-only path, so it
  proves the real endpoint works. It tags the contact with an `external_id`
  prefixed `settings-test-`, which GET /contacts/webhook-activity excludes
  from its count — a convention, not a client-controllable "test mode"
  field on the request itself.

## Errors
- 401 unauth, 403 policy fail, 422 validation/compliance, 429 rate limit.
- 422 `trial_expired`: a trial_expired tenant hit a sending endpoint
  (CSV import, quick-add, the webhook API — see BILLING.md). Not a
  validation failure, but same envelope/status as one — a real,
  actionable "subscribe to continue" state, not a generic error.
- 422 `subscription_ended`: same sending-endpoint gate, but for a tenant
  whose paid subscription stopped renewing and its `current_period_end`
  has passed (BILLING.md's "Auto-renew toggle") — distinct from
  `trial_expired` since this tenant was a paying customer, not someone
  whose trial ran out.
- 422 `email_unverified`: same sending-endpoint gate, for a tenant whose
  owner hasn't clicked their signed verification link yet (AUTH.md) —
  owner-scoped, not whichever user's token made the request.
- Same envelope every time: `{ error, message, fields }` — never a
  differently-shaped body per failure mode, regardless of which endpoint or
  auth method (session or API key) triggered it.
- Never leak stack traces or SQL in responses (APP_DEBUG=false in prod).
