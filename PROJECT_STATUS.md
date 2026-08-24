# ReviewEngine — Project Status

## 1. Scope

ReviewEngine is a multi-tenant SaaS that helps local businesses collect Google
reviews compliantly. A business (tenant) signs up, connects their Google
Business Profile, uploads their past customers, and the system drips out
review requests by email/SMS on a compliant schedule, then auto-replies to
the reviews that come in.

Two kinds of tenants:
- **Admin** (us) — cross-tenant visibility into every customer account.
- **Customer** (the business, e.g. a roofer) — isolated to their own data only.

The core product loop, once built out:
1. Business signs up, picks a plan, pays (Stripe).
2. Connects Google Business Profile (OAuth).
3. Uploads a CSV of past customers.
4. Edits (or keeps the defaults for) 3 message templates and timing rules.
5. The system drips requests to customers, tracks clicks/reviews, suppresses
   follow-ups once a review lands, and posts AI-drafted replies to incoming
   reviews.

**The one hard legal constraint the whole product is built around**: no
review gating. Every customer gets the same review link regardless of
sentiment — there is no code path, ever, that routes people differently
based on how happy they are. This is a Google policy / FTC requirement, not
a feature toggle, and it shapes the compliance-checker requirement on every
message template.

## 2. Tech Stack

| Layer | Choice | Notes |
|---|---|---|
| Backend / API | Laravel 12, PHP 8.3+ | The only code that touches the database |
| Database | Postgres 16 | Shared schema, `tenant_id` + Row-Level Security |
| Queue / Scheduling | Laravel Queues + Horizon + Redis | Deliberately not BullMQ — one language, one backend |
| Frontend | Next.js 16 (App Router), React 19, TypeScript | UI only — no DB access, calls the Laravel API |
| Styling | Tailwind + shadcn/ui | Design tokens (CSS custom properties) for theming/white-label |
| Auth | Laravel Sanctum (API tokens) | Laravel owns auth; frontend never talks to the DB |
| Payments | Lemon Squeezy (lemonsqueezy/laravel) | Single plan, monthly/annual, webhook signature-verified — switched from Stripe (doesn't support Pakistan-domiciled businesses) |
| Email | Resend / Postmark via Laravel Mail | |
| AI | Claude API | Compliance-checks message templates + drafts review replies |
| Hosting (planned) | Laravel on VPS/Forge, Next.js on Vercel, managed Postgres + Redis | |
| Local dev | mkcert-issued TLS certs (`.certs/`) via a local TLS-terminating proxy | So `local` matches `production`'s HTTPS-only assumption |

Architecture rule baked into the docs: **Next.js never touches Postgres.**
It only calls the Laravel REST API (`/api/v1/...`), and Laravel is the
single source of truth for the database.

## 3. Current Setup Status

The repo is a monorepo: `/backend` (Laravel), `/frontend` (Next.js),
`/.claude` (project docs — read before touching anything). It is **not yet
a git repository**.

### Documentation — done
Nine docs in `.claude/` cover architecture rules, security, database/RLS
design, the Google compliance rules, the API contract, the queue design,
testing gates, and a 5-phase build roadmap. This is unusually thorough for
this stage and is the actual spec the code is being built against.

### Backend — Phase 1 complete, gate passed
Built:
- Migrations: `tenants`, `users` (+ `tenant_id`/`role`), `subscriptions`
  (+ `tenant_id`), `audit_logs`, Sanctum's `personal_access_tokens`, and
  migrations enabling Postgres Row-Level Security on every tenant table
  (including the admin-bypass policy) via a non-superuser `app_user` DB
  role.
- `BelongsToTenant` trait + `TenantScope` global scope (Layer A,
  application-level tenant isolation).
- `SetTenantContext` middleware (Layer B — sets the Postgres session
  variable RLS policies read, resolved from the bearer token itself so it
  can run *before* `auth:sanctum`, which needs it to read `users` at all).
- `SecurityHeaders` middleware (CSP, X-Frame-Options, HSTS, etc.).
- Auth: `register` / `login` / `logout`, rate-limited, with a clean JSON
  error envelope on every failure path (401/403/404/422/429).
- Billing: lemonsqueezy/laravel installed (`Billable` on `User`), single
  plan/two intervals ($20/mo, $200/yr — placeholder Lemon Squeezy variant
  IDs pending real Lemon Squeezy products), `POST /subscribe` (returns a
  hosted checkout URL — Lemon Squeezy has no direct-charge API),
  `GET /subscription`, `POST /lemon-squeezy/webhook` with mandatory
  HMAC-SHA256 signature verification (fails closed if misconfigured).
- Admin: `GET /admin/tenants`, `GET /admin/tenants/{id}` — a separate,
  `TenantPolicy`-gated, audited cross-tenant read path (every read writes
  an `audit_logs` row; RLS bypass is scoped to exactly that read).
- A `/health` endpoint the frontend already calls.

**Phase 1 gate — all pass (25/25 tests, including the cross-tenant suite):**
- Cross-tenant isolation with the app-layer scope active (reads + writes).
- Cross-tenant isolation with the app-layer scope *deliberately bypassed*
  — proves Postgres RLS alone blocks it, independent of Eloquent.
- SQL-injection probes across every auth/subscription input.
- Login rate limit fires after 5 attempts.
- Lemon Squeezy webhook rejects missing/bad/wrong-secret signatures, and
  verifies a correctly-signed payload (proving it actually checks, not
  just denies).
- A real, non-Pest HTTP round-trip: register → login → protected route →
  revoked-token rejection, with no `Accept` header (matching a real
  frontend `fetch()` call).

Not yet built: GBP OAuth, CSV import, template editor + compliance
checker, the drip-scheduling engine, review sync/replies, admin UI (the
admin *API* exists; there's no frontend for it yet).

### Known limitations
- Stripe price IDs in `config/plans.php` are placeholders — real Stripe
  Price objects need to exist (test *and* live mode) before `/subscribe`
  can complete an actual charge. Webhook signature verification and the
  subscribe endpoint's validation are both fully testable without them;
  an end-to-end successful subscription is not, until real keys are added.
- The admin path currently requires manually seeding an admin-type tenant
  (no self-serve way to create one — by design, this is "us," not a
  public signup flow).

### Frontend — scaffold only
Still effectively what `create-next-app` produces: a layout, a home page,
one shadcn `Button` component, and a `/health` page that round-trips to the
Laravel API to prove the two apps can talk to each other over HTTPS
locally. None of the four product screens (CSV import wizard, template
editor, campaign dashboard, admin view) exist yet.

## 4. What's Next

Phase 1 is done and gated. Next up is Phase 2 (per `.claude/ROADMAP.md`):
the CSV import wizard, template editor with the Claude compliance checker,
and the Laravel Queue drip-scheduling engine — the first shippable
(email-first) slice of the product.
