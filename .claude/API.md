# API — the contract between Next.js and Laravel

## Rules
- Next.js calls Laravel over HTTPS. Laravel is the only DB access.
- All routes (except login/register/stripe-webhook) require Sanctum auth.
- Every route: Form Request validation → Policy authorization → controller.
- Consistent JSON: { data } on success, { error, message, fields } on failure.
- Version the API: /api/v1/...

## Endpoint groups
Auth:        POST /register, /login, /logout
Tenant:      GET/PATCH /tenant (self)
Subscription:POST /subscribe, GET /subscription, POST /stripe/webhook
GBP:         GET /gbp/connect (OAuth start), GET /gbp/callback, GET /reviews
Campaigns:   CRUD /campaigns
Templates:   CRUD /templates  (save triggers compliance check → may 422)
Contacts:    POST /contacts/import (CSV), GET /contacts
Reviews:     GET /reviews, POST /reviews/{id}/reply
Admin:       GET /admin/tenants, GET /admin/tenants/{id}/*  (logged, admin only)

## Errors
- 401 unauth, 403 policy fail, 422 validation/compliance, 429 rate limit.
- Never leak stack traces or SQL in responses (APP_DEBUG=false in prod).
