"use client";

import { useEffect, useRef, useState } from "react";

import { useHasMounted } from "@/hooks/useHasMounted";

/**
 * A one-time fade/slide reveal when content scrolls into view — the
 * marketing page's standard entrance treatment (hero, how-it-works,
 * pricing, FAQ). Same "additive under motion-safe, never required" rule
 * SinglePathLine's own draw-in animation already follows (see
 * globals.css's comment on --animate-draw-path): the base state rendered
 * here is `opacity-100 translate-y-0` — correct on its own, with no JS and
 * no IntersectionObserver support needed. The `motion-safe:opacity-0
 * motion-safe:translate-y-6` starting point below only ever applies inside
 * `@media (prefers-reduced-motion: no-preference)` — under reduced
 * motion, or before hydration, or if IntersectionObserver is unavailable,
 * content simply never leaves its fully-visible base state.
 *
 * Disconnects after the first reveal — this is an entrance, not a
 * repeating show/hide toggle on every scroll direction change.
 *
 * Always exposes `data-reveal-pending` on the wrapper (alongside the
 * `group` class) so a nested descendant can stagger its own entrance off
 * the same trigger via `group-data-[reveal-pending=true]:` — pair with
 * `staggerReveal()` from `./staggerReveal` (a plain, non-client module —
 * see its own docblock for why it isn't exported from here). Deliberately
 * keyed on "is currently pending" rather than "has been revealed": see
 * the `mounted`/`pendingReveal` comment below for why `revealed` alone
 * can't safely drive a `[foo=false]` CSS selector. Set `revealSelf={false}`
 * when only the staggered children (not the wrapper itself) should
 * visibly move — e.g. a card grid, where the grid container fading AND
 * every card independently fading would be two animations doing the same
 * job.
 *
 * `mounted` (from `useHasMounted()`) is a real bug fix, not decoration: a
 * real Playwright run with JS disabled entirely
 * (e2e/marketing-visual.spec.ts's "worst case of a slow connection" test)
 * found that below-the-fold content stayed invisible forever without it.
 * `revealed` alone isn't enough — under a browser with no reduced-motion
 * preference (the common case), the `motion-safe:opacity-0` starting
 * point applies purely from CSS, with no JS required; only the *reveal*
 * (flipping back to visible) needs JS. If JS never runs, `revealed` never
 * becomes true, and content that was "additive, base-state-correct" in
 * theory stayed hidden in practice. `mounted` gates the hiding classes
 * themselves, so with no JS at all — not just no IntersectionObserver —
 * `mounted` stays false and the base `opacity-100`/`translate-y-0` is the
 * only state ever rendered.
 */
export function RevealOnScroll({
  children,
  className,
  revealSelf = true,
}: {
  children: React.ReactNode;
  className?: string;
  revealSelf?: boolean;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const mounted = useHasMounted();
  const [revealed, setRevealed] = useState(false);

  useEffect(() => {
    const node = ref.current;
    if (node === null || typeof IntersectionObserver === "undefined") {
      setRevealed(true);
      return;
    }

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setRevealed(true);
          observer.disconnect();
        }
      },
      { threshold: 0.15 }
    );

    observer.observe(node);

    return () => observer.disconnect();
  }, []);

  const pendingReveal = mounted && !revealed;

  const selfRevealClass = revealSelf
    ? `motion-safe:transition-all motion-safe:duration-700 motion-safe:ease-out ${
        pendingReveal
          ? "opacity-100 translate-y-0 motion-safe:opacity-0 motion-safe:translate-y-6"
          : "opacity-100 translate-y-0"
      }`
    : "";

  return (
    <div
      ref={ref}
      data-revealed={revealed}
      data-reveal-pending={pendingReveal}
      className={`group ${selfRevealClass} ${className ?? ""}`}
    >
      {children}
    </div>
  );
}
