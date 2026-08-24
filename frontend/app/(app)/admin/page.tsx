import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
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
 * The billing breakdown card that used to sit above the tenants table
 * (GET /admin/billing-breakdown, AdminBillingController) was removed with
 * the Lemon Squeezy package — it aggregated against
 * lemon_squeezy_subscriptions, which no longer exists. Pending a
 * Paddle-backed rebuild.
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
