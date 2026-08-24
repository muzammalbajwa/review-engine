/**
 * Class + inline style for a child that staggers in off the SAME reveal
 * trigger as its ancestor `RevealOnScroll` (via `group`/
 * `data-reveal-pending`), rather than a second IntersectionObserver of
 * its own. Keep groups small (3–4 items, e.g. pricing cards, how-it-works
 * steps) — a long staggered list reads as slow to scan, not purposeful;
 * a longer list (FAQ) should reveal as one block instead.
 *
 * Keyed on `[reveal-pending=true]`, not `[revealed=false]`: a real
 * Playwright run with JS disabled entirely
 * (e2e/marketing-visual.spec.ts) found that `revealed` stays `false`
 * forever with no JS, which would make a `[revealed=false]` selector
 * match — and hide this content — permanently. `reveal-pending` is false
 * both before any JS has run AND once revealed, so it only ever matches
 * during the real in-between window. See RevealOnScroll.tsx's own
 * `mounted`/`pendingReveal` comment for the full explanation.
 *
 * Deliberately NOT in RevealOnScroll.tsx: that file is `"use client"`, and
 * a plain function export from a Client Component module can't be called
 * from a Server Component — only rendered as JSX. This one has no hooks
 * or browser APIs, so it needs no directive and works from either side.
 */
export function staggerReveal(index: number, { baseDelayMs = 150, stepMs = 90 } = {}) {
  return {
    className:
      "opacity-100 translate-y-0 motion-safe:transition-all motion-safe:duration-500 motion-safe:ease-out motion-safe:group-data-[reveal-pending=true]:opacity-0 motion-safe:group-data-[reveal-pending=true]:translate-y-2",
    style: { transitionDelay: `${baseDelayMs + index * stepMs}ms` },
  };
}
