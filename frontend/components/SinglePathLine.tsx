"use client";

import { CheckCircle2, Circle, Send, Star } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { useHasMounted } from "@/hooks/useHasMounted";
import { cn } from "@/lib/utils";

// A string key, not a component reference: this file is a Client
// Component, and call sites (the marketing hero's Server Component page)
// can't pass a Lucide icon component across that boundary — React only
// allows plain serializable props from Server to Client Components. The
// actual icon components stay local to this module.
const STEP_ICONS = {
  check: CheckCircle2,
  send: Send,
  star: Star,
} as const;

export type SinglePathIconKey = keyof typeof STEP_ICONS;

export type SinglePathStep = {
  label: string;
  /** Defaults to a plain dot if omitted — set this for a real, meaningful marker. */
  icon?: SinglePathIconKey;
};

export type SinglePathLineProps = {
  /** The points along the path, left to right. */
  steps: SinglePathStep[];
  /**
   * If set, the line stops here instead of reaching the last step — the
   * "blocked" state. Index into `steps`. Omit entirely for the passing,
   * unbroken line.
   */
  blockedAtIndex?: number;
  /** Shown next to the stop marker when blocked. */
  blockedReason?: string;
  /**
   * Defer the draw-in until this component scrolls into view, instead of
   * drawing on mount. Use this for the marketing hero's placement (below
   * the fold — drawing on mount would finish before anyone sees it); leave
   * it off for the template compliance checker, which is already on screen
   * and should react immediately as a template is edited.
   */
  revealOnScroll?: boolean;
  className?: string;
};

/**
 * The one signature element of the ReviewEngine design system (see
 * /DESIGN.md, "Signature"). A thin, deliberately unbranching line: there
 * is no code path in this component for a fork, exactly like the product
 * itself has no code path for gating a review request by sentiment.
 *
 * Used in exactly two places (both still to be wired up): the marketing
 * hero ("customer finishes a job" -> "review posted", always unbroken —
 * never called with blockedAtIndex there), and the template compliance
 * checker (a blocked template stops the line at a red marker with the
 * reason). Not a general-purpose progress bar — don't reach for this
 * where a plain step indicator would do.
 */
export function SinglePathLine({
  steps,
  blockedAtIndex,
  blockedReason,
  revealOnScroll = false,
  className,
}: SinglePathLineProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mounted = useHasMounted();
  const [revealed, setRevealed] = useState(!revealOnScroll);

  useEffect(() => {
    if (!revealOnScroll) return;
    const node = containerRef.current;
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
      { threshold: 0.4 }
    );

    observer.observe(node);
    return () => observer.disconnect();
  }, [revealOnScroll]);

  const isBlocked = blockedAtIndex !== undefined;
  const stopIndex = isBlocked ? blockedAtIndex : steps.length - 1;
  const positions = steps.map((_, i) => (steps.length === 1 ? 0 : (i / (steps.length - 1)) * 100));

  // Gated on `mounted`, not just `revealed`: without `mounted`, a real
  // Playwright run with JS disabled entirely
  // (e2e/marketing-visual.spec.ts) found this stayed hidden forever —
  // `revealed` starts false and nothing ever flips it without JS, so
  // `revealOnScroll && !revealed` alone stayed true permanently. Pending
  // is only true in the real in-between window: JS has started (mounted)
  // but hasn't intersected yet (!revealed). With no JS at all, `mounted`
  // never becomes true, so pending is always false and the always-correct
  // base state (dashoffset={0} below, fully visible icons) is the only
  // thing ever rendered.
  const pending = revealOnScroll && mounted && !revealed;

  // Pre-reveal, the line is undrawn (dashoffset 100 — nothing visible) so
  // there's something to draw once revealed. The inline dashoffset={0}
  // fallback below is the always-correct base: reduced motion, no-JS, and
  // pre-hydration all just show the finished, fully-drawn line instantly.
  const lineClassName = pending ? "motion-safe:[stroke-dashoffset:100]" : "motion-safe:animate-draw-path";

  return (
    <div ref={containerRef} className={cn("w-full", className)}>
      <div className="relative h-9 w-full">
        <svg
          viewBox="0 0 100 8"
          preserveAspectRatio="none"
          className="absolute inset-x-0 top-1/2 h-2 w-full -translate-y-1/2 overflow-visible"
          aria-hidden="true"
        >
          <line
            x1={0}
            y1={4}
            x2={positions[stopIndex]}
            y2={4}
            stroke={isBlocked ? "var(--destructive)" : "var(--primary)"}
            strokeWidth={1.5}
            strokeLinecap="round"
            vectorEffect="non-scaling-stroke"
            pathLength={100}
            strokeDasharray={100}
            strokeDashoffset={0}
            className={lineClassName}
          />
          {isBlocked && stopIndex < steps.length - 1 && (
            <line
              x1={positions[stopIndex]}
              y1={4}
              x2={100}
              y2={4}
              stroke="var(--border)"
              strokeWidth={1.5}
              strokeDasharray="1 3"
              strokeLinecap="round"
              vectorEffect="non-scaling-stroke"
            />
          )}
        </svg>

        {steps.map((step, i) => {
          const isStop = isBlocked && i === stopIndex;
          const isPastStop = isBlocked && i > stopIndex;
          const Icon = step.icon ? STEP_ICONS[step.icon] : Circle;

          return (
            <div
              key={i}
              aria-hidden="true"
              style={{ left: `${positions[i]}%`, transitionDelay: revealOnScroll ? `${150 + i * 120}ms` : undefined }}
              className={cn(
                "absolute top-1/2 -translate-x-1/2 -translate-y-1/2 motion-safe:transition-all motion-safe:duration-500 motion-safe:ease-out",
                pending ? "motion-safe:scale-75 motion-safe:opacity-0" : ""
              )}
            >
              <span
                className={cn(
                  "flex size-7 items-center justify-center rounded-full border-2 border-background shadow-sm",
                  isStop
                    ? "bg-destructive text-destructive-foreground"
                    : isPastStop
                      ? "bg-muted text-muted-foreground"
                      : "bg-primary text-primary-foreground"
                )}
              >
                <Icon className="size-3.5" strokeWidth={2.5} />
              </span>
            </div>
          );
        })}
      </div>

      <div className="mt-1 flex text-xs">
        {steps.map((step, i) => {
          const isStop = isBlocked && i === stopIndex;
          const isPastStop = isBlocked && i > stopIndex;

          return (
            <div
              key={i}
              className={cn(
                "flex-1 basis-0",
                i === 0 ? "text-left" : i === steps.length - 1 ? "text-right" : "text-center"
              )}
            >
              <span
                className={cn(
                  "font-medium",
                  isStop ? "text-destructive" : isPastStop ? "text-muted-foreground/50" : "text-foreground"
                )}
              >
                {step.label}
              </span>
            </div>
          );
        })}
      </div>

      {isBlocked && blockedReason && <p className="mt-2 text-sm text-destructive">{blockedReason}</p>}
    </div>
  );
}
