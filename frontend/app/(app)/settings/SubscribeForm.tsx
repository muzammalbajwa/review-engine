"use client";

import { useCallback, useEffect, useState } from "react";
import { initializePaddle, type Environments, type Paddle } from "@paddle/paddle-js";

import { Button } from "@/components/ui/button";
import { PLAN } from "@/lib/plans";
import { subscribe, type PaddleCheckoutOptions } from "./actions";

const INTERVALS = [
  { key: "monthly", label: "Monthly", priceId: PLAN.priceId.month, badge: null },
  { key: "annual", label: "Annual", priceId: PLAN.priceId.year, badge: "2 months free" },
] as const;

/**
 * Never silently default sandbox/production — an unset value here would
 * mean "production" by falling through, which could run checkout against
 * live Paddle credentials by mistake. Thrown, not swallowed, so a missing
 * env var in a new environment fails loudly at first use, not silently
 * as a wrong charge later.
 */
function resolvePaddleEnvironment(): Environments {
  const raw = process.env.NEXT_PUBLIC_PADDLE_SANDBOX;

  if (raw !== "true" && raw !== "false") {
    throw new Error(
      `NEXT_PUBLIC_PADDLE_SANDBOX must be set to "true" or "false" (got ${JSON.stringify(raw)}). Refusing to ` +
        "default it — an unset value here could silently run checkout against live Paddle credentials.",
    );
  }

  return raw === "true" ? "sandbox" : "production";
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
 * server action) only returns the items/customer/customData payload;
 * opening the overlay, previewing localized prices, and reacting to the
 * overlay's own completion event all happen client-side, since Paddle.js
 * itself only runs in the browser.
 */
export function SubscribeForm({ currentInterval }: { currentInterval: "monthly" | "annual" | null }) {
  const [selectedInterval, setSelectedInterval] = useState<(typeof INTERVALS)[number]["key"]>(
    currentInterval ?? "monthly",
  );
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [paddle, setPaddle] = useState<Paddle | null>(null);
  // Paddle's own formattedTotals per interval — never re-derived or
  // re-formatted here (no Intl.NumberFormat, no frontend price math).
  // null until PricePreview resolves; a missing entry (price ID
  // misconfigured) is shown as unavailable rather than a stale guess.
  const [formattedTotals, setFormattedTotals] = useState<Partial<Record<"monthly" | "annual", string>>>({});
  const [priceError, setPriceError] = useState(false);
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
  const missingPriceIds = !PLAN.priceId.month || !PLAN.priceId.year;

  const setupPaddle = useCallback(async () => {
    if (!clientSideToken || missingPriceIds) {
      return;
    }

    const instance = await initializePaddle({
      token: clientSideToken,
      environment: resolvePaddleEnvironment(),
      eventCallback(event) {
        if (event.name === "checkout.completed") {
          setCheckoutCompleted(true);
        }
      },
    });

    if (!instance) {
      return;
    }

    setPaddle(instance);

    // No country/address passed — Paddle.PricePreview() auto-detects the
    // visitor's locale from their IP. If this app ever sits behind
    // Cloudflare, a real country header could be wired in here; it isn't
    // available in this environment (Vercel-style geo headers don't apply).
    try {
      const preview = await instance.PricePreview({
        items: [
          { priceId: PLAN.priceId.month, quantity: 1 },
          { priceId: PLAN.priceId.year, quantity: 1 },
        ],
      });

      const totals: Partial<Record<"monthly" | "annual", string>> = {};
      for (const lineItem of preview.data.details.lineItems) {
        if (lineItem.price.id === PLAN.priceId.month) {
          totals.monthly = lineItem.formattedTotals.total;
        } else if (lineItem.price.id === PLAN.priceId.year) {
          totals.annual = lineItem.formattedTotals.total;
        }
      }
      setFormattedTotals(totals);
    } catch {
      setPriceError(true);
    }
  }, [clientSideToken, missingPriceIds]);

  useEffect(() => {
    // Deferred, not called directly: same pattern TemplateEditor.tsx uses
    // for its own async-on-mount fetch, sidesteps
    // react-hooks/set-state-in-effect flagging the (legitimate) async
    // network calls inside setupPaddle as a synchronous cascading-render
    // risk.
    const timeout = setTimeout(setupPaddle, 0);
    return () => clearTimeout(timeout);
  }, [setupPaddle]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    if (!clientSideToken || missingPriceIds) {
      setError("Checkout isn't configured yet. Try again shortly.");
      return;
    }

    if (!paddle) {
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

    paddle.Checkout.open(mergeCheckoutSettings(result.checkout));
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5">
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
              {formattedTotals[interval.key] ?? (priceError ? "Price unavailable" : "Loading…")}
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

      {(!clientSideToken || missingPriceIds) && (
        <p role="status" className="text-sm text-muted-foreground">
          Checkout is temporarily unavailable. Please try again later or contact support.
        </p>
      )}

      <Button type="submit" disabled={submitting || !clientSideToken || missingPriceIds}>
        {submitting ? "Loading checkout…" : "Continue to checkout"}
      </Button>
    </form>
  );
}

/**
 * POST /subscribe returns only items/customer/customData (Laravel
 * Cashier's Checkout::options()) — the display settings below are
 * client-only concerns, so they're merged in here rather than added to
 * the backend response. One-page overlay: no redirect away from Settings,
 * no separate multi-step checkout page. successUrl points back at this
 * same tab since Settings/Billing is the only entry point this app has
 * for checkout today — an onboarding-triggered checkout path, if one is
 * ever added, would need its own success destination (.claude/BILLING.md).
 */
function mergeCheckoutSettings(options: PaddleCheckoutOptions) {
  const appUrl = process.env.NEXT_PUBLIC_APP_URL;

  return {
    ...options,
    settings: {
      ...options.settings,
      displayMode: "overlay" as const,
      variant: "one-page" as const,
      ...(appUrl ? { successUrl: `${appUrl}/settings?tab=billing` } : {}),
    },
  };
}
