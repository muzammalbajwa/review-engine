"use client";

import { Button } from "@/components/ui/button";

/**
 * A different case from the `!result.ok` inline error rendering every
 * page already does for a failed API call (that's handled as data, not an
 * exception, everywhere in this app). This is the fallback for an actual
 * unexpected crash — a real bug — so it exists at all, following
 * .claude/DESIGN.md's voice: say what happened and what to do next, no
 * apology.
 */
export default function GlobalError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <div className="flex max-w-sm flex-col items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-6">
        <p className="font-heading text-base font-semibold text-destructive">Something went wrong</p>
        <p className="text-sm text-foreground">
          This page hit an unexpected error. Try again, or come back to it in a moment.
        </p>
        <Button onClick={reset} variant="outline">
          Try again
        </Button>
      </div>
    </main>
  );
}
