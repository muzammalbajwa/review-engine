import Link from "next/link";

import { AppNav } from "@/components/AppNav";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

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
 * empty page.
 */
export default async function AdminTenantsPage() {
  await requireToken();

  const result = await apiFetch<Tenant[]>("/admin/tenants");

  return (
    <main className="flex min-h-screen justify-center p-8">
      <div className="w-full max-w-4xl">
        <AppNav />
        <h1 className="mb-6 text-lg font-semibold">All tenants</h1>

        {!result.ok ? (
          <AdminAccessError status={result.status} message={result.message} />
        ) : (
          <TenantTable tenants={result.data} />
        )}
      </div>
    </main>
  );
}

function TenantTable({ tenants }: { tenants: Tenant[] }) {
  if (tenants.length === 0) {
    return <p className="text-sm text-muted-foreground">No tenants yet.</p>;
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <table className="w-full text-left text-sm">
        <caption className="sr-only">All tenants across every account</caption>
        <thead>
          <tr className="border-b border-border bg-muted/50 text-muted-foreground">
            <th scope="col" className="px-4 py-2 font-medium">
              Name
            </th>
            <th scope="col" className="px-4 py-2 font-medium">
              Type
            </th>
            <th scope="col" className="px-4 py-2 font-medium">
              Created
            </th>
            <th scope="col" className="px-4 py-2 font-medium">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {tenants.map((tenant) => (
            <tr key={tenant.id} className="border-b border-border last:border-0">
              <td className="px-4 py-2 font-medium text-foreground">{tenant.name}</td>
              <td className="px-4 py-2 capitalize text-muted-foreground">{tenant.type}</td>
              <td className="px-4 py-2 text-muted-foreground">
                <time dateTime={tenant.created_at}>
                  {new Date(tenant.created_at).toLocaleDateString()}
                </time>
              </td>
              <td className="px-4 py-2 text-right">
                <Link
                  href={`/admin/tenants/${tenant.id}`}
                  className="rounded font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                >
                  View<span className="sr-only"> {tenant.name}</span>
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
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
