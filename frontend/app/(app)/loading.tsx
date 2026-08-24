/**
 * .claude/DESIGN.md audit: every screen here is a Server Component that
 * fetches from the Laravel API before rendering — with no loading.tsx
 * anywhere, every one of them showed a blank screen during that fetch.
 * Next.js wraps the segment (and everything under it) in a Suspense
 * boundary keyed to this file, so one root-level loading state covers
 * every route's data-fetch window, not just this one.
 *
 * The dot always pulses (a status indicator, not decorative motion — kept
 * outside prefers-reduced-motion) at a fixed opacity-only, non-jarring
 * rate; only the ring's spin is gated by motion-safe.
 */
export default function Loading() {
  return (
    <div className="flex min-h-screen items-center justify-center" role="status" aria-label="Loading">
      <div className="flex items-center gap-3">
        <span className="relative flex size-4">
          <span className="absolute inline-flex size-full animate-pulse rounded-full bg-primary/40" />
          <span className="relative inline-flex size-4 rounded-full border-2 border-primary border-t-transparent motion-safe:animate-spin" />
        </span>
        <span className="text-sm text-muted-foreground">Loading…</span>
      </div>
    </div>
  );
}
