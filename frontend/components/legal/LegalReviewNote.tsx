/**
 * Flags a clause drafted from a real gap or an assumption this session
 * couldn't verify in the codebase (hosting region, governing jurisdiction,
 * DPA/SCC status, etc.) rather than silently guessing. Same warning
 * treatment DESIGN.md already uses elsewhere for "needs attention but
 * nothing is broken" states (BillingBreakdownCard's variants_configured
 * banner) — gold, not red, since this isn't a compliance-blocked error,
 * it's a marked gap for counsel to close before either page is relied on.
 */
export function LegalReviewNote({ children }: { children: React.ReactNode }) {
  return (
    <p
      role="note"
      className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs text-foreground"
    >
      <strong className="font-semibold">NEEDS LEGAL REVIEW —</strong> {children}
    </p>
  );
}
