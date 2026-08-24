# Components

Shared, reusable pieces. See `/DESIGN.md` for the design system these are
built against — the tokens, not hardcoded values, are what make these
reusable across light/dark and future screens.

## `ui/button.tsx`

`Button` — variants `default` (primary/moss), `outline`, `secondary` (gold),
`ghost`, `destructive` (reserved red — only use for an actual destructive
action or error, per `/DESIGN.md`'s color rule), `link`. Sizes `xs`/`sm`/
`default`/`lg` plus icon-only variants. Unchanged this pass — it already
read every color through tokens, so it picked up the new palette for free.

## `ui/card.tsx`

`Card`, `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`,
`CardFooter` — a plain composable card, the surface primitive most future
screens (dashboard KPI strip, settings sections, admin drill-in) will build
on. `CardTitle` uses the display font (`font-heading`) automatically.

## `SinglePathLine.tsx`

The one signature element (`/DESIGN.md`, "Signature"). Renders a thin,
literally-unbranching line across a row of steps, each marked with a real
icon badge (moss green; a plain dot if a step has no `icon`). A Client
Component (`"use client"`) — it tracks its own reveal state — so `icon` is
a string key (`"check" | "send" | "star"`, see `STEP_ICONS` in the file),
never a Lucide component reference: Server Components can't pass a
function/component prop across the Client Component boundary.

```tsx
<SinglePathLine steps={[{ label: "Job finished", icon: "check" }, { label: "Review posted", icon: "star" }]} />

<SinglePathLine
  steps={[{ label: "Draft" }, { label: "Check" }, { label: "Live" }]}
  blockedAtIndex={1}
  blockedReason="Asks for a specific star rating."
/>

{/* Below the fold: defers the draw-in until it scrolls into view instead
    of drawing on mount (used by the marketing hero's NoGatingSection). */}
<SinglePathLine revealOnScroll steps={[...]} />
```

Reserved for its two intended uses (marketing hero — wired up in
`app/page.tsx`'s `NoGatingSection` — and the template compliance checker,
still not wired up) — not a general-purpose progress bar or step
indicator. If a screen needs a plain multi-step indicator that isn't
illustrating "no gating branch," build a separate, simpler component for
that rather than reusing this one out of context.

## Previewing all of the above

`/style-guide` — palette swatches, type scale, both button variants, a card
example, and both `SinglePathLine` states, rendered together. Check this
page after any token change in `globals.css` before touching a real screen.
