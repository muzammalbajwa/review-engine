"use client";

import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

const INTERVALS = [
  { key: "monthly", label: "Monthly", price: "$20", suffix: "/mo", badge: null },
  { key: "annual", label: "Annual", price: "$200", suffix: "/yr", badge: "2 months free" },
] as const;

/**
 * The marketing page's single-plan pricing card (.claude/BILLING.md's
 * pricing change replaced the old Starter/Growth/Pro three-card grid).
 * The monthly/annual toggle here is a preview only — it doesn't post
 * anywhere or start a trial; the actual interval choice happens at real
 * conversion (Settings/Billing, after a trial exists), same as onboarding's
 * PlanSelector.tsx.
 */
export function PricingCard() {
  const [interval, setInterval] = useState<(typeof INTERVALS)[number]["key"]>("monthly");
  const selected = INTERVALS.find((i) => i.key === interval)!;

  return (
    <Card className="mx-auto flex w-full max-w-sm flex-col items-center gap-6 bg-background p-8 text-center">
      <h3 className="font-heading text-lg font-semibold text-foreground">ReviewEngine</h3>

      <div
        role="radiogroup"
        aria-label="Billing interval"
        className="inline-flex rounded-full border border-border p-1"
      >
        {INTERVALS.map((i) => (
          <button
            key={i.key}
            type="button"
            role="radio"
            aria-checked={interval === i.key}
            onClick={() => setInterval(i.key)}
            className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
              interval === i.key
                ? "bg-primary text-primary-foreground"
                : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {i.label}
          </button>
        ))}
      </div>

      <div className="flex flex-col items-center gap-1">
        <p>
          {interval === "monthly" && (
            <span className="mr-1.5 text-lg font-normal text-muted-foreground line-through">$25</span>
          )}
          <span className="font-heading text-4xl font-semibold text-foreground">{selected.price}</span>
          <span className="text-sm text-muted-foreground">{selected.suffix}</span>
        </p>
        {selected.badge && (
          <span className="text-xs font-medium tracking-wide text-primary uppercase">{selected.badge}</span>
        )}
      </div>

      <p className="text-sm text-muted-foreground">
        Automatic review requests, AI-drafted replies, and the compliance checker — all included.
      </p>

      <Button render={<Link href="/register" />} nativeButton={false} className="w-full">
        Start free
      </Button>
      <p className="text-xs text-muted-foreground">7 days free, no card required.</p>
    </Card>
  );
}
