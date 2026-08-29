import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import { TenantsTable } from "./TenantsTable";

type Tenant = {
  id: string;
  name: string;
  type: "admin" | "customer";
  created_at: string;
  plan: string | null;
  billing_interval: "monthly" | "annual" | null;
  status: "pending" | "trialing" | "active" | "past_due" | "trial_expired" | "canceled";
  trial_ends_at: string | null;
  auto_renew: boolean | null;
  next_renewal_date: string | null;
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
 * Per-tenant billing state (plan/interval/status/renewal/auto-renew) now
 * comes back on this same GET /admin/tenants response
 * (AdminTenantController::index()) and is rendered inline in
 * TenantsTable — an admin scanning this list can see who's past_due
 * without a second view. The aggregate MRR/ARR breakdown card that used
 * to sit above the table (GET /admin/billing-breakdown,
 * AdminBillingController) is a separate thing and is still not rebuilt —
 * it aggregated against lemon_squeezy_subscriptions, which no longer
 * exists, and was removed with the Lemon Squeezy package. Pending a
 * Paddle-backed rebuild if that aggregate view is still wanted.
 */
export default async function AdminTenantsPage() {
  await requireToken();

  const tenantsResult = await apiFetch<Tenant[]>("/admin/tenants");

  return (
    <main className="flex justify-center p-8">
      <div className="w-full max-w-4xl">
        <h1 className="mb-6 text-lg font-semibold">All tenants</h1>

        {!tenantsResult.ok ? (
          <AdminAccessError status={tenantsResult.status} message={tenantsResult.message} />
        ) : (
          <TenantsTable tenants={tenantsResult.data} />
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
