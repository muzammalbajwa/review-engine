type IntervalBucket = {
  tenant_count: number;
  price_cents: number | null;
  mrr_cents: number;
  arr_cents: number;
};

export type BillingBreakdown = {
  by_interval: { monthly: IntervalBucket; annual: IntervalBucket };
  unrecognized_variant: IntervalBucket;
  // False when LEMON_SQUEEZY_VARIANT_MONTHLY/_ANNUAL aren't both set to
  // distinct real values yet (this dev environment right now, for
  // instance) — every active subscription lands in unrecognized_variant
  // in that state, not because the underlying data is wrong.
  variants_configured: boolean;
  totals: { tenant_count: number; mrr_cents: number; arr_cents: number };
};

function formatCents(cents: number): string {
  return (cents / 100).toLocaleString("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 });
}

/**
 * Real aggregation against lemon_squeezy_subscriptions (GET
 * /admin/billing-breakdown, AdminBillingController — audit-logged like
 * every other cross-tenant admin read). MRR is monthly-equivalent: an
 * annual tenant's price is amortized over 12 months so the two intervals
 * are directly comparable in one figure, not just displayed side by side.
 */
export function BillingBreakdownCard({ breakdown }: { breakdown: BillingBreakdown }) {
  return (
    <div className="rounded-lg border border-border p-5">
      <h2 className="text-sm font-medium text-foreground">Billing breakdown</h2>
      <p className="mt-1 text-xs text-muted-foreground">Active, paying tenants only — trials aren&apos;t counted.</p>

      {!breakdown.variants_configured && (
        <p
          role="alert"
          className="mt-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs text-foreground"
        >
          LEMON_SQUEEZY_VARIANT_MONTHLY and _ANNUAL aren&apos;t both set to distinct real values yet — every
          active subscription below is showing as unrecognized instead of split by interval, since there&apos;s
          no way to tell monthly and annual apart until those are configured.
        </p>
      )}

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <IntervalTile label="Monthly" bucket={breakdown.by_interval.monthly} />
        <IntervalTile label="Annual" bucket={breakdown.by_interval.annual} />
        <div className="rounded-lg border border-primary/30 bg-primary/5 p-4">
          <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Total</p>
          <p className="mt-2 text-2xl font-semibold tabular-nums text-foreground">
            {breakdown.totals.tenant_count}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {formatCents(breakdown.totals.mrr_cents)} MRR · {formatCents(breakdown.totals.arr_cents)} ARR
          </p>
        </div>
      </div>

      {breakdown.unrecognized_variant.tenant_count > 0 && breakdown.variants_configured && (
        <p role="alert" className="mt-4 text-xs text-destructive">
          {breakdown.unrecognized_variant.tenant_count} active subscription
          {breakdown.unrecognized_variant.tenant_count === 1 ? "" : "s"} on a variant ID that doesn&apos;t match the
          current monthly or annual price — real, paying tenants not reflected in the MRR/ARR above. Check
          config/plans.php against what&apos;s actually configured in Lemon Squeezy.
        </p>
      )}
    </div>
  );
}

function IntervalTile({ label, bucket }: { label: string; bucket: IntervalBucket }) {
  return (
    <div className="rounded-lg border border-border p-4">
      <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
      <p className="mt-2 text-2xl font-semibold tabular-nums text-foreground">{bucket.tenant_count}</p>
      <p className="mt-1 text-xs text-muted-foreground">
        {formatCents(bucket.mrr_cents)} MRR · {formatCents(bucket.arr_cents)} ARR
      </p>
    </div>
  );
}
