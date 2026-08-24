# ReviewEngine — Design System

This is the design brief this app is built against. Read this before styling
anything — it exists so a new session doesn't re-derive a generic shadcn look
from scratch. If a screen's styling contradicts this document, the document
wins; fix the screen.

## Who this is for

A local service business owner (roofer, plumber, salon, contractor) checking
this on a phone between jobs — not a SaaS buyer evaluating software for a
living. The personality is plainspoken, trustworthy, unglamorous: closer to a
good invoice or a well-organized toolbox than a Silicon Valley SaaS product.

**Deliberately avoided**: the cream/serif/terracotta look, the near-black/
neon-accent look, and the broadsheet-hairline look. All three are 2026's
AI-generated-design defaults; using any of them would make this look
generated rather than built for tradespeople.

## Color

All colors live as CSS custom properties in `app/globals.css` (`:root` for
light, `.dark` for dark) and are exposed as Tailwind utilities (`bg-primary`,
`text-destructive`, etc.) via the `@theme inline` block. **Never hardcode a
hex value in a component** — every color a component needs already has a
token; if it doesn't, add the token to `globals.css`, don't inline a hex.

| Token | Light hex | Role |
|---|---|---|
| `--background` | `#F5F6F2` | Page background — chalky off-white, cool/sage-tinted, never warm cream |
| `--foreground` | `#1E2A22` | Primary text, headers — deep forest-charcoal |
| `--primary` / `--success` | `#2F6F4E` | Moss green — buttons, active nav, links, "compliant"/"connected" states. **One color for both roles, not two** — the brief treats the brand accent and the "things are fine" state as the same idea. |
| `--secondary` / `--warning` | `#C99A3E` | Warm gold — secondary accent, used sparingly (CTA emphasis, highlights, "needs attention but nothing is broken" states like a pending reply) |
| `--destructive` | `#B3432B` | Reserved red. **Rule: appears nowhere except an actual compliance-blocked state or a real error.** Never decorative, never a generic warning. If you find red anywhere else in this app, that's a bug — fix it, don't add a reason for it. |
| `--card`, `--popover` | `#FFFFFF` | Surfaces that sit on top of the page background |
| `--border`, `--muted`, `--input` | `#EDEFEA` | Dividers, muted surfaces |

Dark mode is a considered second pass, not an automatic invert: background
drops to `#14201A` (a near-black forest, not pure black), the moss/gold/red
accents are each brightened slightly (`#4C9670` / `#D9AE5C` / `#D9694E`) so
they keep working at proper contrast against a dark ground — same hues, same
personality, tuned for the surface.

`--chart-1` through `--chart-5` are a five-step tint/shade ramp of the moss
primary (light → dark), for ordered/magnitude data (e.g. "sends by step").
No separate categorical chart palette exists yet — if a future screen needs
to distinguish unrelated categories (not an ordered sequence), that's a new
decision to make deliberately, not an excuse to reach for `--chart-*` or an
inline hex.

## Type

Three roles, three families, all self-hosted via `next/font/google` (no
external font CDN request at runtime):

- **Display** (`font-heading`, mapped to Space Grotesk) — headlines, card
  titles, section headers. Applied automatically to every `h1`–`h6` via
  `@layer base` in `globals.css`. Used with restraint: confident, not
  decorative.
- **Body** (`font-sans`, mapped to Inter) — everything else. This is the
  default (`html` sets `font-sans`), so most elements need no font class.
- **Data/utility** (`font-mono`, mapped to IBM Plex Mono) — tables,
  timestamps, IDs, audit log entries. Use this specifically wherever
  precision matters; it's part of the "we take the compliance details
  seriously" feeling, not just a monospace-for-numbers habit.

Stick to Tailwind's default type scale (`text-sm` … `text-4xl`) — no custom
scale was needed once the three font roles were set correctly.

## Layout

- **Radius**: base `--radius` is `0.875rem` (14px), inside the brief's
  12–16px range. Rounded reads as approachable for this audience — never
  drop to the zero-radius broadsheet look. The existing `--radius-sm` …
  `--radius-4xl` scale derives proportionally from this one value; don't
  hardcode a radius in a component.
- **App shell**: 260px sidebar (not built yet — no sidebar component
  exists as of this document; when one is built, use `16.25rem` as its
  width), 12-column content grid (`grid-cols-12`, Tailwind's built-in
  utility — no custom token needed), KPI card strip pattern for dashboard
  homes.
- **Marketing**: generous whitespace, single-column reading width for copy
  sections.

## Signature — "the single path"

One element, used in exactly two places — not scattered as decoration:

1. **Marketing hero**: an unbroken line running from "customer finishes a
   job" to "review posted," with no fork anywhere in it. This is the
   literal illustration of the product's actual differentiator: there is no
   code path that gates a review request by sentiment, ever.
2. **Template compliance checker**: the same line motif. A passing template
   shows the unbroken line; a blocked one shows the line stopping at a red
   marker with the reason.

Implemented once as `components/SinglePathLine.tsx` — see
`components/README.md` for its API. Neither integration exists yet (the
hero and the compliance checker are future work); this step only built and
previewed the primitive itself, at `/style-guide`.

## Voice

- Plain verbs, second person, no SaaS jargon: "Import your customers," not
  "Ingest your customer data."
- Say what will happen, not that it has been abstractly scheduled: "This
  review request will go out at 6pm," not "Your campaign has been
  scheduled."
- Errors say what happened and what to do. Never apologize, never say
  "oops."
- An action keeps its name through the whole flow — a button that says
  "Import" produces a result that says "Imported," not "Success."

## What changed here vs. the previous (shadcn-default) look

The token *names* are unchanged (`primary`, `destructive`, `warning`,
`success`, `muted`, etc.) — only their values changed, in `globals.css`
alone. Every existing screen (contacts import, templates, reviews,
analytics, admin, GBP) already reads colors through these tokens rather than
hardcoded hex, so they now render in the new palette automatically, without
being individually edited. Two things worth knowing if a screen suddenly
looks different than expected:

- Anything previously styled `text-warning`/`bg-warning` (e.g. the "needs
  reply" pill) now renders in gold, not the old amber — that's the
  deliberate remap above, not a regression.
- Anything styled `text-success`/`bg-success` now renders in the same moss
  green as `primary` — also deliberate.
