# DATABASE — Postgres 16, multi-tenant, RLS-enforced

## Isolation model: shared schema + tenant_id + Row-Level Security
Chosen for 100–10,000 tenants. Not separate DBs (that's HIPAA-tier overkill).

## Every tenant table MUST have:
- `tenant_id` (uuid, FK → tenants.id, ON DELETE CASCADE)
- an index on tenant_id (without it, queries do full table scans and die at scale)

## Core tables
tenants          (id, name, type[admin|customer], created_at, plan,
                  status[pending|trialing|active|trial_expired|canceled],
                  billing_interval[monthly|annual], trial_started_at,
                  trial_ends_at) — see BILLING.md. The 7-day free trial is
                  tracked ENTIRELY here, never in `lemon_squeezy_subscriptions`:
                  no Lemon Squeezy Customer/Subscription is created at
                  trial start, only at real conversion.
users            (id, tenant_id, email, password, role,
                  has_completed_welcome_tour, tours_seen[jsonb], ...) —
                  product-tour progress, deliberately per-user (a tenant
                  with multiple team members needs each person's own
                  "have I seen this," not one shared flag) and
                  server-side (a device switch or cleared browser data
                  must never re-trigger a tour already completed).
lemon_squeezy_subscriptions (id, tenant_id, billable_id/type, lemon_squeezy_id,
                  status, variant_id, renews_at, ends_at,
                  renewal_reminder_10d_sent_for, renewal_reminder_5d_sent_for,
                  ...) — lemonsqueezy/laravel's own table, tenant_id + RLS
                  added on top (the package doesn't know about tenants).
                  tenant.status/billing_interval are still the
                  authoritative fields the rest of the app reads — this
                  table only ever supplies `renews_at`/`ends_at` (and the
                  auto-renew toggle's state, derived from `status`/`ends_at`
                  rather than a stored column — see BILLING.md's
                  "Auto-renew toggle"). The two `renewal_reminder_*d_sent_for`
                  columns are billing:send-renewal-reminders' own
                  idempotency tracking — see BILLING.md's "Renewal
                  reminders" for why they store a date, not a boolean.
gbp_connections  (id, tenant_id, oauth_token[encrypted], location_id, review_link)
sender_identities(id, tenant_id, type[email|sms], from_address, verified)
campaigns        (id, tenant_id, type[live|reactivation], status)
templates        (id, tenant_id, campaign_id, step[1|2|3], body, compliance_status)
timing_rules     (id, tenant_id, campaign_id, step, delay_minutes, business_hours, tz)
contacts         (id, tenant_id, campaign_id, name, phone, email,
                  status[pending|sent|clicked|reviewed|stopped], consent_at)
messages         (id, tenant_id, contact_id, step, sent_at, provider_id, status)
reviews          (id, tenant_id, google_review_id, rating, text, created_at)
replies          (id, tenant_id, review_id, body, posted_at, policy_violation)
audit_logs       (id, tenant_id, actor_id, action, target, meta, created_at)

## RLS setup (run in a migration, version-controlled)
For each tenant table:
  ALTER TABLE <t> ENABLE ROW LEVEL SECURITY;
  ALTER TABLE <t> FORCE ROW LEVEL SECURITY;   -- critical: owner can't bypass
  CREATE POLICY tenant_isolation ON <t>
    USING (tenant_id = current_setting('app.current_tenant_id')::uuid);

## App DB role
- The app connects as `app_user`, a NON-superuser, NON-owner role.
- Superuser/owner is used ONLY for migrations, never at runtime.

## Setting tenant context (Laravel middleware, every request)
- Resolve tenant from auth()->user()->tenant_id (NEVER from request input).
- DB::statement("SET LOCAL app.current_tenant_id = ?", [$tenantId]);
- Wrap in a transaction so SET LOCAL is scoped to the request.

## Admin cross-tenant reads
- Admin path sets a flag and uses a policy branch that allows all tenants.
- EVERY admin cross-tenant read writes an audit_log row.

## Migrations
- Every schema change is a Laravel migration. No manual DB edits ever.
- Back up production before migrating. Migrations run in transactions.
