# SECURITY — read before every backend task. Non-negotiable.

## Threat model
We hold customers' business data, customer contact lists (PII: names, phones,
emails), OAuth tokens to their Google accounts, and payment data. A breach ends
the company. Treat every rule here as load-bearing.

## 1. SQL Injection — PREVENTION IS MANDATORY
- Use Eloquent ORM or the Query Builder for ALL database access.
- NEVER use DB::raw(), whereRaw(), orderByRaw() with user input concatenated in.
  If a raw expression is unavoidable, use bound parameters ONLY:
  ✅ DB::select('... where email = ?', [$email])
  ❌ DB::select("... where email = '$email'")
- All input is validated by a Form Request before it reaches a controller.
- Never pass user input into: column names, table names, raw order-by, or
  raw SQL. If a user picks a sort column, whitelist it against an allow-list.

## 2. Multi-tenant isolation — DEFENSE IN DEPTH (two layers, both required)
Layer A — Application: a global Eloquent scope adds `where tenant_id = ?` to
every query automatically. All tenant models use a `BelongsToTenant` trait.
Layer B — Database: Postgres Row-Level Security. Even if a query forgets the
scope, the database refuses to return other tenants' rows.
- Every request sets the tenant context from the authenticated user's token,
  NEVER from a client-supplied header/param the user can forge.
- Before any query: SET LOCAL app.current_tenant_id = <resolved tenant>.
- Enable RLS with FORCE on every tenant table. The app connects as a
  NON-superuser role that cannot bypass RLS.
- Admin cross-tenant access is a SEPARATE explicit code path, logged every time.

## 3. Authentication & authorization
- Laravel Sanctum issues API tokens. Next.js stores the token in an
  httpOnly, Secure, SameSite=Strict cookie — never in localStorage.
- Every API route is behind auth:sanctum middleware. No exceptions except
  /login, /register, /lemon-squeezy/webhook (which uses signature
  verification), and a handful of other signed/token-authenticated public
  links a real person clicks from an email or a URL they hold (not a
  Sanctum bearer token) — /email/verify/{id}/{hash}, sender-identity
  verification, GBP OAuth's own callback, team invites, quick-add.
- Authorization uses Policies. A customer can only act on their own tenant's
  resources. Check the Policy on EVERY write action.
- Passwords: bcrypt (Laravel default). Never store or log plaintext.
- Rate-limit login (throttle:5,1) and all public endpoints.

## 4. Input validation & output
- Every endpoint has a Form Request with explicit rules. Reject unknown fields.
- Validate CSV uploads: size cap, mime check, row cap, per-cell length cap,
  strip formulas (cells starting with = + - @ → prefix with ') to stop CSV
  injection into Excel.
- Escape all output. Next.js/React escapes by default; never use
  dangerouslySetInnerHTML with user content.

## 5. Secrets & config
- All secrets in .env. .env is gitignored. Provide .env.example with blanks.
- Lemon Squeezy webhook: verify the signature (HMAC-SHA256 of the raw body
  vs. the X-Signature header) on every call.
- Google OAuth tokens: encrypt at rest (Laravel encrypted casts).
- API keys (Claude, Resend) server-side only. Never in the Next.js bundle
  (never prefix with NEXT_PUBLIC_ unless it is truly public).

## 6. Transport & headers
- HTTPS everywhere. HSTS on.
- Security headers: X-Content-Type-Options, X-Frame-Options DENY,
  Content-Security-Policy, Referrer-Policy.
- CORS: allow ONLY the known frontend origin. No wildcards.

## 7. Abuse & messaging safety
- Idempotency keys on all send jobs — a contact can never be messaged twice
  for the same step.
- Per-tenant send rate limits enforced server-side (a compromised or greedy
  tenant cannot blast).
- Full audit log: who sent what, when, to whom, and every admin cross-tenant read.

## 8. Dependencies & CI
- Run `composer audit` and `npm audit` in CI; block on high/critical.
- Pin versions. Review dependabot updates before merging.

## The security self-check (state this before every backend PR)
- [ ] All DB access via Eloquent/Query Builder, no raw user input in SQL
- [ ] tenant_id enforced by global scope AND RLS
- [ ] Form Request validates every input
- [ ] Policy authorizes every write
- [ ] No secret added to client bundle or git
- [ ] Cross-tenant test still passes
