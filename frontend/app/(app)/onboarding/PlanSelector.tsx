"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { startTrial } from "./actions";

/**
 * The 7-day free trial's onboarding UI (.claude/BILLING.md: "do NOT
 * create a Paddle Customer/Subscription at trial start... no card, no
 * Paddle call"). No checkout overlay here at all — that's the whole
 * point of this step now. Billing interval (monthly/annual) and card
 * collection both move to Settings/Billing's conversion flow
 * (BillingSection.tsx/SubscribeForm.tsx, Paddle's overlay checkout),
 * reached only once a tenant actually wants to pay, whether that's right
 * away or after the trial ends — there's nothing to choose here since
 * there's only one plan (.claude/BILLING.md's single-plan pricing
 * change).
 */
export function PlanSelector({ onStarted }: { onStarted: () => void }) {
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const result = await startTrial("standard");
    setSubmitting(false);

    if (result.status === "error") {
      setError(result.message);
      return;
    }

    onStarted();
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-6">
      <div className="flex flex-col items-center gap-1 rounded-lg border border-border p-6 text-center">
        <span className="font-heading text-base font-semibold text-foreground">ReviewEngine</span>
        <span className="text-2xl font-semibold text-foreground">
          <span className="mr-1.5 text-lg font-normal text-muted-foreground line-through">$25</span>
          $20
          <span className="text-sm font-normal text-muted-foreground">/mo</span>
        </span>
        <span className="text-xs text-muted-foreground">
          Billed monthly or annually — pick whichever when your trial ends.
        </span>
      </div>

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <Button type="submit" disabled={submitting}>
        {submitting ? "Starting your trial…" : "Start free trial"}
      </Button>
      <p className="text-center text-xs text-muted-foreground">
        7 days free, no card required. Add a card any time from billing settings.
      </p>
    </form>
  );
}
