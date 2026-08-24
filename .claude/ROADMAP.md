# ROADMAP — build in this order. Do not skip ahead.

## Phase 0 — Foundations (Week 1, parallel; do the slow ones day 1)
- US LLC + EIN (unblocks SMS later + payments)
- Apply for Google Business Profile API access (long lead — apply immediately;
  needs a live website + verified profile >60 days)
- Lemon Squeezy account (merchant of record — Stripe doesn't support
  Pakistan-domiciled businesses); privacy + T&C pages
- Scaffold monorepo: /backend Laravel 12, /frontend Next.js 16, /.claude docs
- Get both apps running locally, talking over the API, HTTPS in dev.

## Phase 1 — Tenancy + security spine (Weeks 2–3)
- tenants/users/subscriptions tables + migrations
- BelongsToTenant trait + global scope
- RLS migration (ENABLE + FORCE + policy) + non-superuser app role
- Tenant-context middleware (resolve from auth, SET LOCAL)
- Sanctum auth; Lemon Squeezy (lemonsqueezy/laravel) + single plan +
  webhook (signature-verified)
- GATE: cross-tenant isolation test passes. Nothing proceeds until it does.

## Phase 2 — The engine (Weeks 4–6) — SHIP HERE (email-first)
- CSV import wizard (with CSV-injection guards)
- Template editor + Claude compliance checker
- Laravel Queue scheduler: 3-message engine, follow-up suppression,
  reactivation drip (rate-limit + per-tenant business hours + tz)
- Resend email + click tracking
- Horizon + worker heartbeat alert
- GATE: testing gates 2,3,4,5,6 pass.

## Phase 3 — Google + replies (Weeks 7–9)
- GBP OAuth (encrypted tokens); review sync
- Claude-generated replies with delayed posting
- Surface PolicyViolation on rejected replies
- Analytics dashboard

## Phase 4 — SMS (Weeks 10–14)
- A2P 10DLC / ISV setup, per-client brand registration, toll-free fallback

## Phase 5 — Polish/stickiness
- White-label (design tokens pay off); multi-location;
  Jobber/Housecall Pro/QuickBooks auto-trigger on job completion
