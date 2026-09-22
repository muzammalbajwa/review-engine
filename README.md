# ReviewEngine

Multi-tenant SaaS for compliant Google review collection. Local businesses
(tenants) sign up, connect their Google Business Profile, upload past
customers, and the system drips out compliant review requests and auto-replies
to reviews.

## Repo layout (monorepo)

```
/backend    Laravel 12 API — the only code that touches the database
/frontend   Next.js 16 UI — calls the Laravel API only, no DB access
/.claude    Project docs (read these before working on anything)
```

## Start here

Read `/.claude/CLAUDE.md` first, then the doc relevant to what you're
touching (`SECURITY.md`, `DATABASE.md`, `API.md`, `FRONTEND.md`, etc.).

## Local development

See `/.claude/ROADMAP.md` for build order and `/backend/README.md` /
`/frontend/README.md` (once scaffolded) for run commands.

A full local pass (checkout/webhook/email verification, not just
`php artisan test`) needs five separate background processes running at
once: the Laravel backend (`:8123`), the queue worker, Mailpit
(`:8025`), an ngrok tunnel (for Paddle to reach the local webhook
endpoint), and the Next.js frontend (`:3000`). None of them are
supervised — any one can die silently mid-session with nothing to flag
it (this happened to Mailpit during 2026-09-22's Paddle sandbox
verification).

Two scripts check/start all five, with real evidence (PID, port, or the
live ngrok tunnel URL) rather than assuming anything is running:

```
backend/scripts/dev-status.sh   # checks all five, exits non-zero if any are down
backend/scripts/dev-up.sh       # starts whatever's down, leaves the rest alone
```

Run `dev-status.sh` at the start of a session like this one; run
`dev-up.sh` to bring up whatever it flagged. Neither is a process
supervisor — nothing here auto-restarts a crash, and if `dev-up.sh`
(re)starts ngrok, it prints the new tunnel URL and a reminder that the
Paddle sandbox webhook destination needs updating to match (ngrok's
free-tier URL changes on every restart).
