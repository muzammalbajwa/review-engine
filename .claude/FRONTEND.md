# FRONTEND — Next.js 16, App Router, top-class UI

## Rules
- UI only. No DB access. No secrets in client bundle. Calls Laravel API.
- App Router + Server Components. TypeScript strict mode.
- shadcn/ui + Tailwind. Design tokens (CSS custom properties) for all colors,
  spacing, radii, typography — NOT hard-coded. Enables dark mode + white-label.
- WCAG AA: keyboard nav, focus states, labels, contrast.
- Every form: inline validation, error summary, loading/disabled states.

## The four screens that carry the product (spend design effort here)
1. CSV import wizard — upload → column mapping → validation preview → confirm.
2. Template editor — 3 steps, live compliance check with inline pass/block.
3. Campaign dashboard — reviews gained, rating over time, requests, click rate.
4. Admin god-view — all tenants, drill-in, usage, billing.

## Auth handling
- Token in httpOnly Secure cookie. Never localStorage.
- Protected routes check session server-side before render.
