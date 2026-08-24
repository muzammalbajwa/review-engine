import Link from "next/link";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

type AuditLogRow = {
  id: number;
  actor: { id: number; name: string; email: string } | null;
  action: string;
  target: string;
  tenant: { id: string; name: string } | null;
  meta: Record<string, unknown> | null;
  created_at: string;
};

type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

type SearchParams = { [key: string]: string | string[] | undefined };

function first(value: string | string[] | undefined): string {
  return Array.isArray(value) ? (value[0] ?? "") : (value ?? "");
}

/**
 * .claude/DATABASE.md: "audit_logs (id, tenant_id, actor_id, action,
 * target, meta, created_at)" — existed since Phase 1 with no dedicated UI.
 * Filters live in the URL (a plain GET form, no client JS) so a filtered
 * view is shareable/bookmarkable, same as it would be for any other
 * internal admin tool — .claude/DESIGN.md dashboard-pattern guidance:
 * search/filter above the table, not behind a menu.
 */
export default async function AdminAuditLogPage({
  searchParams,
}: {
  searchParams: Promise<SearchParams>;
}) {
  await requireToken();
  const params = await searchParams;

  const action = first(params.action);
  const tenantId = first(params.tenant_id);
  const from = first(params.from);
  const to = first(params.to);
  const page = first(params.page) || "1";

  const query = new URLSearchParams();
  if (action) query.set("action", action);
  if (tenantId) query.set("tenant_id", tenantId);
  if (from) query.set("from", from);
  if (to) query.set("to", to);
  query.set("page", page);

  const result = await apiFetch<Paginated<AuditLogRow>>(`/admin/audit-log?${query.toString()}`);

  return (
    <main className="flex justify-center p-8">
      <div className="w-full max-w-5xl">
        <h1 className="mb-6 text-lg font-semibold">Audit log</h1>

        <form className="mb-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
          <Field label="Action" name="action" defaultValue={action} placeholder="e.g. admin.tenants.view" />
          <Field label="Target tenant ID" name="tenant_id" defaultValue={tenantId} placeholder="uuid" />
          <Field label="From" name="from" type="date" defaultValue={from} />
          <Field label="To" name="to" type="date" defaultValue={to} />
          <button
            type="submit"
            className="h-9 shrink-0 rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/80 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
          >
            Filter
          </button>
          {(action || tenantId || from || to) && (
            <Link
              href="/admin/audit-log"
              className="text-sm font-medium text-muted-foreground underline-offset-4 hover:underline"
            >
              Clear
            </Link>
          )}
        </form>

        {!result.ok ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
          >
            {result.status === 403
              ? "You don't have admin access to view this page."
              : result.status === 401
                ? "Your session has expired. Log in again to continue."
                : result.message}
          </p>
        ) : result.data.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {action || tenantId || from || to
              ? "No audit log entries match these filters."
              : "No audit log entries yet."}
          </p>
        ) : (
          <>
            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full text-left text-sm">
                <caption className="sr-only">Admin audit log</caption>
                <thead>
                  <tr className="border-b border-border bg-muted/50 text-muted-foreground">
                    <th scope="col" className="px-4 py-2 font-medium">
                      Admin
                    </th>
                    <th scope="col" className="px-4 py-2 font-medium">
                      Action
                    </th>
                    <th scope="col" className="px-4 py-2 font-medium">
                      Target tenant
                    </th>
                    <th scope="col" className="px-4 py-2 font-medium">
                      When
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {result.data.data.map((row) => (
                    <tr key={row.id} className="border-b border-border last:border-0">
                      <td className="px-4 py-2 text-foreground">
                        {row.actor ? (
                          <>
                            <span className="font-medium">{row.actor.name}</span>
                            <span className="ml-1 text-xs text-muted-foreground">{row.actor.email}</span>
                          </>
                        ) : (
                          <span className="text-muted-foreground">Unknown</span>
                        )}
                      </td>
                      <td className="px-4 py-2 font-mono text-xs text-foreground">{row.action}</td>
                      <td className="px-4 py-2 text-muted-foreground">
                        {row.tenant ? (
                          <Link
                            href={`/admin/tenants/${row.tenant.id}`}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                          >
                            {row.tenant.name}
                          </Link>
                        ) : (
                          <span className="italic">all tenants</span>
                        )}
                      </td>
                      <td className="px-4 py-2 text-muted-foreground">
                        <time dateTime={row.created_at} className="font-mono text-xs">
                          {new Date(row.created_at).toLocaleString()}
                        </time>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <Pagination
              currentPage={result.data.current_page}
              lastPage={result.data.last_page}
              total={result.data.total}
              baseQuery={query}
            />
          </>
        )}
      </div>
    </main>
  );
}

function Field({
  label,
  name,
  defaultValue,
  placeholder,
  type = "text",
}: {
  label: string;
  name: string;
  defaultValue: string;
  placeholder?: string;
  type?: string;
}) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      <input
        type={type}
        name={name}
        defaultValue={defaultValue}
        placeholder={placeholder}
        className="h-9 rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
      />
    </label>
  );
}

function Pagination({
  currentPage,
  lastPage,
  total,
  baseQuery,
}: {
  currentPage: number;
  lastPage: number;
  total: number;
  baseQuery: URLSearchParams;
}) {
  if (lastPage <= 1) {
    return <p className="mt-3 text-xs text-muted-foreground">{total} total</p>;
  }

  function hrefForPage(page: number): string {
    const next = new URLSearchParams(baseQuery);
    next.set("page", String(page));
    return `/admin/audit-log?${next.toString()}`;
  }

  return (
    <div className="mt-3 flex items-center justify-between text-sm">
      <p className="text-xs text-muted-foreground">
        Page {currentPage} of {lastPage} &middot; {total} total
      </p>
      <div className="flex gap-2">
        {currentPage > 1 && (
          <Link
            href={hrefForPage(currentPage - 1)}
            className="rounded-lg border border-border px-3 py-1 font-medium text-foreground hover:bg-muted"
          >
            Previous
          </Link>
        )}
        {currentPage < lastPage && (
          <Link
            href={hrefForPage(currentPage + 1)}
            className="rounded-lg border border-border px-3 py-1 font-medium text-foreground hover:bg-muted"
          >
            Next
          </Link>
        )}
      </div>
    </div>
  );
}
