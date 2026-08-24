"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { subscribe } from "./actions";

const INTERVALS = [
  { key: "monthly", label: "Monthly", price: "$20", suffix: "/mo", badge: null },
  { key: "annual", label: "Annual", price: "$200", suffix: "/yr", badge: "2 months free" },
] as const;

/**
 * The conversion flow — the only place in Settings/Billing a tenant picks
 * a billing interval. Shown for a 'trialing' tenant converting early, or a
 * 'trial_expired' one converting to get sending access back.
 *
 * Unlike the old Stripe flow, there's no card field here at all — Lemon
 * Squeezy is hosted-checkout only (.claude/BILLING.md). Submitting always
 * ends in a redirect to their checkout page; this component's only job is
 * picking monthly vs. annual.
 */
export function SubscribeForm({ currentInterval }: { currentInterval: "monthly" | "annual" | null }) {
  const [selectedInterval, setSelectedInterval] = useState<(typeof INTERVALS)[number]["key"]>(
    currentInterval ?? "monthly",
  );
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const result = await subscribe(selectedInterval);

    // A success redirects away entirely (subscribe() calls redirect()) —
    // reaching this line at all means it didn't.
    setSubmitting(false);

    if (result?.status === "error") {
      setError(result.message);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5">
      <div>
        <p className="text-sm text-muted-foreground">
          <span className="text-muted-foreground line-through">$25</span>{" "}
          <span className="font-semibold text-foreground">$20/mo</span>
        </p>
      </div>

      <div role="radiogroup" aria-label="Billing interval" className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {INTERVALS.map((interval) => (
          <label
            key={interval.key}
            className={`flex cursor-pointer flex-col gap-1 rounded-lg border p-3 ${
              selectedInterval === interval.key ? "border-primary ring-2 ring-primary/20" : "border-border"
            }`}
          >
            <input
              type="radio"
              name="interval"
              value={interval.key}
              checked={selectedInterval === interval.key}
              onChange={() => setSelectedInterval(interval.key)}
              className="sr-only"
            />
            {interval.badge && (
              <span className="text-xs font-medium tracking-wide text-primary uppercase">{interval.badge}</span>
            )}
            <span className="font-heading text-sm font-semibold text-foreground">{interval.label}</span>
            <span className="text-base font-semibold text-foreground">
              {interval.price}
              <span className="text-sm text-muted-foreground">{interval.suffix}</span>
            </span>
          </label>
        ))}
      </div>

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <Button type="submit" disabled={submitting}>
        {submitting ? "Redirecting…" : "Continue to checkout"}
      </Button>
    </form>
  );
}
