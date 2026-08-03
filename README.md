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
