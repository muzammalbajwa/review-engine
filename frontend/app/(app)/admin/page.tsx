import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import { BillingBreakdownCard, type BillingBreakdown } from "./BillingBreakdownCard";
import { TenantsTable } from "./TenantsTable";

type Tenant = {
  id: string;
  name: string;
  type: "admin" | "customer";
  created_at: string;
};

/**
 * .claude/API.md: "Admin: GET /admin/tenants... (logged, admin only)".
 * .claude/FRONTEND.md screen 4: "Admin god-view — all tenants, drill-in".
 * Server Component, calls the Laravel API only (never the DB directly) —
 * the actual admin gate is TenantPolicy::viewAny on the backend; a non-admin
 * hitting this page gets a 403 from the API, shown below, not a silent
 * empty page. Chrome (the read-only banner, admin nav) comes from
 * app/admin/layout.tsx, not this page.
 *
 * Billing breakdown fetched here too, not a separate page — the closest
 * "cross-tenant view" to a monthly/annual/MRR/ARR summary is this same
 * all-tenants overview, not a new admin nav destination for one card.
 * Failing independently of the tenants list (each its own try): a broken
 * breakdown read shouldn't hide the tenant table someone came here for,
 * same "each section fails independently" convention Settings already
 * uses.
 */
export default async function AdminTenantsPage() {
  await requireToken();

  const [tenantsResult, billingResult] = await Promise.all([
    apiFetch<Tenant[]>("/admin/tenants"),
    apiFetch<BillingBreakdown>("/admin/billing-breakdown"),
  ]);

  return (
    <main className="flex justify-center p-8">
      <div className="w-full max-w-4xl">
        <h1 className="mb-6 text-lg font-semibold">All tenants</h1>

        {!tenantsResult.ok ? (
          // Both reads hit the exact same admin gate (TenantPolicy::viewAny)
          // — a non-admin's request fails both identically, so this one
          // error covers the whole page rather than repeating the same
          // "you're not an admin" message twice.
          <AdminAccessError status={tenantsResult.status} message={tenantsResult.message} />
        ) : (
          <>
            <div className="mb-6">
              {billingResult.ok ? (
                <BillingBreakdownCard breakdown={billingResult.data} />
              ) : (
                <AdminAccessError status={billingResult.status} message={billingResult.message} />
              )}
            </div>

            <TenantsTable tenants={tenantsResult.data} />
          </>
        )}
      </div>
    </main>
  );
}

function AdminAccessError({ status, message }: { status: number; message: string }) {
  const text =
    status === 403
      ? "You don't have admin access to view this page."
      : status === 401
        ? "Your session has expired. Log in again to continue."
        : message;

  return (
    <p
      role="alert"
      className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
    >
      {text}
    </p>
  );
}
