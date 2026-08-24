"use client";

import Script from "next/script";
import { useCallback, useState } from "react";

import { Button } from "@/components/ui/button";
import { subscribe, type PaddleCheckoutOptions } from "./actions";

const INTERVALS = [
  { key: "monthly", label: "Monthly", price: "$20", suffix: "/mo", badge: null },
  { key: "annual", label: "Annual", price: "$200", suffix: "/yr", badge: "2 months free" },
] as const;

// Paddle.js is loaded via <script>, not an npm package — this is the
// minimal shape of window.Paddle this component actually calls, not a
// full SDK type. See developer.paddle.com/paddlejs/methods for the rest.
declare global {
  interface Window {
    Paddle?: {
      Environment: { set: (env: "sandbox" | "production") => void };
      Initialize: (options: { token: string; eventCallback?: (event: { name: string }) => void }) => void;
      Checkout: { open: (options: PaddleCheckoutOptions) => void };
    };
  }
}

/**
 * The conversion flow — the only place in Settings/Billing a tenant picks
 * a billing interval. Shown for a "trialing"/"trial_expired" tenant
 * converting, or a "canceled" one subscribing again.
 *
 * Paddle's overlay checkout (.claude/BILLING.md — Cashier's standard
 * integration pattern): Paddle.js runs in the browser and opens an
 * in-page checkout frame — there's no redirect away from this screen the
 * way a hosted-checkout page would need. POST /subscribe (the `subscribe`
 * server action) only returns the options payload; opening the overlay
 * and reacting to its own completion event both happen client-side, since
 * Paddle.js itself only runs in the browser.
 */
export function SubscribeForm({ currentInterval }: { currentInterval: "monthly" | "annual" | null }) {
  const [selectedInterval, setSelectedInterval] = useState<(typeof INTERVALS)[number]["key"]>(
    currentInterval ?? "monthly",
  );
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [paddleReady, setPaddleReady] = useState(false);
  // Paddle's checkout overlay closing (successfully or not) is the one
  // signal available client-side — activation itself is asynchronous
  // (the real subscription_created webhook, not this event), so this can
  // only ever mean "the customer finished paying inside the overlay,"
  // never "the tenant is active now." Matches BILLING.md's own
  // "practical effect: a real gap between paying and tenant.status
  // reading active" — the copy below says so rather than implying
  // instant activation.
  const [checkoutCompleted, setCheckoutCompleted] = useState(false);

  const clientSideToken = process.env.NEXT_PUBLIC_PADDLE_CLIENT_SIDE_TOKEN;
  const sandbox = process.env.NEXT_PUBLIC_PADDLE_SANDBOX === "true";

  const initializePaddle = useCallback(() => {
    if (!window.Paddle || !clientSideToken) {
      return;
    }

    if (sandbox) {
      window.Paddle.Environment.set("sandbox");
    }

    window.Paddle.Initialize({
      token: clientSideToken,
      eventCallback(event) {
        if (event.name === "checkout.completed") {
          setCheckoutCompleted(true);
        }
      },
    });

    setPaddleReady(true);
  }, [clientSideToken, sandbox]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    if (!clientSideToken) {
      setError("Checkout isn't configured yet. Try again shortly.");
      return;
    }

    if (!paddleReady || !window.Paddle) {
      setError("Checkout is still loading. Try again in a moment.");
      return;
    }

    setSubmitting(true);
    setError(null);
    setCheckoutCompleted(false);

    const result = await subscribe(selectedInterval);

    setSubmitting(false);

    if (result.status === "error") {
      setError(result.message);
      return;
    }

    window.Paddle.Checkout.open(result.checkout);
  }

  return (
    <>
      <Script src="https://cdn.paddle.com/paddle/v2/paddle.js" strategy="afterInteractive" onLoad={initializePaddle} />

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

        {checkoutCompleted && (
          <p role="status" className="text-sm text-foreground">
            Payment received — your plan will activate in a moment. Refresh this page if it doesn&apos;t update on
            its own shortly.
          </p>
        )}

        <Button type="submit" disabled={submitting || !clientSideToken}>
          {submitting ? "Loading checkout…" : "Continue to checkout"}
        </Button>
      </form>
    </>
  );
}
