# PROJECT: ReviewEngine — Multi-Tenant Google Review SaaS

## What we are building
A multi-tenant SaaS where local businesses (tenants) collect Google reviews
compliantly. Two tenant types:
- ADMIN (us): full cross-tenant god-access.
- CUSTOMER (the business, e.g. a roofer): isolated, sees only their own data.

Customers sign up, pick a plan, pay, connect their Google Business Profile,
upload a past-customer CSV, edit 3 message templates, set timing, and the
system drips out compliant review requests and auto-replies to reviews.

## THE GOLDEN RULES (never violate)
1. Laravel is the ONLY code that touches the database. Next.js NEVER connects
   to Postgres. Next.js calls Laravel's REST API only.
2. Every DB table with tenant data has a `tenant_id`. Every query is scoped by
   it. Enforced by a global scope AND Postgres Row-Level Security. Both. Always.
3. The system CANNOT gate reviews. There is no code path that sends a different
   link based on customer sentiment. This is illegal under Google's April 2026
   rules and is a permanent architectural constraint, not a feature toggle.
4. No message template can be saved without passing the compliance checker.
5. Never trust input. Validate every request with a Form Request class. Never
   build a raw SQL string from user input.
6. Secrets live in .env only. Never in code, never in git, never in Next.js
   client bundles.

## Tech stack (do not substitute without asking)
- Backend/API: Laravel 12 (latest), PHP 8.3+, Postgres 16
- Queue/Scheduler: Laravel Queues + Horizon + Redis (NOT BullMQ — PHP only)
- Frontend: Next.js 16 (App Router), React 19, TypeScript, Tailwind, shadcn/ui
- Auth: Laravel Sanctum (API tokens) — Laravel owns auth
- Payments: Paddle (laravel/cashier-paddle) — merchant of record, hosted
  checkout only. Switched from Lemon Squeezy (see BILLING.md — that doc
  still describes the Lemon Squeezy design pending a rewrite; the Paddle
  integration is being built incrementally: package/config/schema first,
  checkout/webhook logic next). Originally switched off Stripe (which
  doesn't support Pakistan-domiciled businesses).
- Email: Resend/Postmark via Laravel Mail
- LLM: Claude API (compliance checks + review replies)
- Hosting: Laravel on a VPS/Forge, Next.js on Vercel, Postgres + Redis managed

## Repo layout (monorepo)
/backend    → Laravel API (source of truth)
/frontend   → Next.js (UI only, calls /backend API)
/.claude    → these docs

## How to work with me
- I give prompts. You write code. You always read the relevant .md file first.
- Before writing ANY code, state which .md rules apply to this task.
- Never skip validation or auth "to make it work for now." Security is not a
  later phase.
- After each feature, tell me which tests to run (see TESTING.md).
- When unsure about a security or compliance decision, STOP and ask me.

## Read next
- ARCHITECTURE.md — how the pieces fit
- SECURITY.md — the security rules (READ BEFORE EVERY BACKEND TASK)
- AUTH.md — email verification (MustVerifyEmail, the owner-scoped sending gate)
- DATABASE.md — schema + multi-tenancy
- COMPLIANCE.md — the Google review rules baked into the product
- API.md — endpoint contract between Next.js and Laravel
- BILLING.md — the 7-day free trial + Lemon Squeezy conversion design
- FRONTEND.md — UI standards
- QUEUE.md — the scheduling engine
- TESTING.md — what must be tested before shipping
- ROADMAP.md — build order, phase by phase
