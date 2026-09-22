/**
 * The single-plan config (.claude/BILLING.md — replaces the old
 * Starter/Growth/Pro three-tier design). Shaped as a `Plan` rather than
 * two bare env var reads so a future second tier only needs a second
 * entry in an array, not a new component — SubscribeForm.tsx reads
 * PLAN.priceId, never process.env directly.
 *
 * priceId values are Paddle Price IDs (format `pri_...`), the frontend
 * mirror of backend/config/plans.php's `intervals.*.price`. Not secret —
 * used only for Paddle.PricePreview()'s localized price display.
 * Paddle.Checkout.open() never uses these directly: POST /subscribe
 * resolves interval -> Price ID server-side and returns the checkout
 * options, so a client can never submit a raw price ID that decides what
 * gets charged (.claude/SECURITY.md #1).
 */
export interface Plan {
  name: "ReviewEngine";
  description: string;
  features: string[];
  priceId: { month: string; year: string };
}

export const PLAN: Plan = {
  name: "ReviewEngine",
  description: "Compliant Google review requests, on autopilot.",
  features: [
    "Automated review request campaigns",
    "Compliance-checked message templates",
    "Google Business Profile review replies",
  ],
  priceId: {
    month: process.env.NEXT_PUBLIC_PADDLE_PRICE_MONTHLY ?? "",
    year: process.env.NEXT_PUBLIC_PADDLE_PRICE_ANNUAL ?? "",
  },
};
