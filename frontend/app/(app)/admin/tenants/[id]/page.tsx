import Link from "next/link";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

type Tenant = {
  id: string;
  name: string;
  type: "admin" | "customer";
  created_at: string;
};

export default async function AdminTenantDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  await requireToken();
  const { id } = await params;

  const result = await apiFetch<Tenant>(`/admin/tenants/${id}`);

  return (
    <main className="flex justify-center p-8">
      <div className="w-full max-w-2xl">
        <Link
          href="/admin"
          className="mb-6 inline-block rounded text-sm font-medium text-muted-foreground hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          &larr; All tenants
        </Link>

        {!result.ok ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
          >
            {result.status === 403
              ? "You don't have admin access to view this page."
              : result.status === 404
                ? "That tenant doesn't exist."
                : result.message}
          </p>
        ) : (
          <div className="rounded-lg border border-border">
            <h1 className="border-b border-border px-6 py-4 text-lg font-semibold">
              {result.data.name}
            </h1>
            <dl className="grid grid-cols-1 gap-4 px-6 py-4 sm:grid-cols-2">
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Type</dt>
                <dd className="mt-1 text-sm capitalize text-foreground">{result.data.type}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Created</dt>
                <dd className="mt-1 text-sm text-foreground">
                  <time dateTime={result.data.created_at}>
                    {new Date(result.data.created_at).toLocaleString()}
                  </time>
                </dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Tenant ID</dt>
                <dd className="mt-1 font-mono text-xs text-foreground">{result.data.id}</dd>
              </div>
            </dl>
            <div className="border-t border-border px-6 py-4">
              <Link
                href={`/admin/audit-log?tenant_id=${result.data.id}`}
                className="rounded text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
              >
                View audit log for this tenant &rarr;
              </Link>
            </div>
          </div>
        )}
      </div>
    </main>
  );
}
