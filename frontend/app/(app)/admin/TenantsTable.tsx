"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

type TenantStatus = "pending" | "trialing" | "active" | "past_due" | "trial_expired" | "canceled";

type Tenant = {
  id: string;
  name: string;
  type: "admin" | "customer";
  created_at: string;
  plan: string | null;
  billing_interval: "monthly" | "annual" | null;
  status: TenantStatus;
  trial_ends_at: string | null;
  auto_renew: boolean | null;
  next_renewal_date: string | null;
};

const TYPE_FILTERS = ["all", "admin", "customer"] as const;
type TypeFilter = (typeof TYPE_FILTERS)[number];

const STATUS_FILTERS = ["all", "trialing", "active", "past_due", "trial_expired", "canceled", "pending"] as const;
type StatusFilter = (typeof STATUS_FILTERS)[number];

// .claude/DESIGN.md: warning (gold) is "needs attention but nothing is
// broken" — past_due is exactly that (Paddle is still retrying;
// BillingSection.tsx's own past_due copy makes the same call). Destructive
// red is reserved for an actual compliance-blocked state or a real error,
// neither of which past_due is on its own.
const STATUS_LABELS: Record<TenantStatus, string> = {
  pending: "No plan yet",
  trialing: "Trial",
  active: "Active",
  past_due: "Past due",
  trial_expired: "Trial expired",
  canceled: "Canceled",
};

const STATUS_PILL_CLASSES: Record<TenantStatus, string> = {
  pending: "bg-muted text-muted-foreground",
  trialing: "bg-primary/10 text-primary",
  active: "bg-primary/10 text-primary",
  past_due: "bg-warning/15 text-warning-foreground border border-warning/40",
  trial_expired: "bg-destructive/10 text-destructive",
  canceled: "bg-muted text-muted-foreground",
};

const INTERVAL_LABELS: Record<"monthly" | "annual", string> = {
  monthly: "Monthly",
  annual: "Annual",
};

type SortKey = "name" | "status" | "next_renewal_date" | "created_at";

/**
 * .claude/DESIGN.md dashboard-pattern guidance: search/filter lives above
 * the table as visible controls, not behind a menu. The full tenant list
 * (now including per-tenant billing state — AdminTenantController::index())
 * is fetched once server-side; filtering/sorting client-side here is the
 * same tradeoff the original type filter already made — dozens of
 * tenants, not thousands.
 *
 * past_due gets three separate, redundant signals (a dot next to the
 * name, a gold pill in the Status column, and a tinted row background) —
 * .claude/FRONTEND.md's screen-4 ask was specifically "don't bury it in a
 * column nobody notices," so this doesn't rely on any single one of them
 * being the thing an admin happens to look at.
 */
export function TenantsTable({ tenants }: { tenants: Tenant[] }) {
  const [query, setQuery] = useState("");
  const [typeFilter, setTypeFilter] = useState<TypeFilter>("all");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [sort, setSort] = useState<{ key: SortKey; dir: "asc" | "desc" } | null>(null);

  const pastDueCount = useMemo(() => tenants.filter((t) => t.status === "past_due").length, [tenants]);

  const filtered = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();

    const rows = tenants.filter((tenant) => {
      if (typeFilter !== "all" && tenant.type !== typeFilter) {
        return false;
      }
      if (statusFilter !== "all" && tenant.status !== statusFilter) {
        return false;
      }
      if (normalizedQuery !== "" && !tenant.name.toLowerCase().includes(normalizedQuery)) {
        return false;
      }
      return true;
    });

    if (sort === null) {
      return rows;
    }

    const sorted = [...rows].sort((a, b) => {
      const av = sortValue(a, sort.key);
      const bv = sortValue(b, sort.key);
      if (av === bv) return 0;
      if (av === null) return 1;
      if (bv === null) return -1;
      return av < bv ? -1 : 1;
    });

    return sort.dir === "asc" ? sorted : sorted.reverse();
  }, [tenants, query, typeFilter, statusFilter, sort]);

  function toggleSort(key: SortKey) {
    setSort((current) => {
      if (current?.key !== key) return { key, dir: "asc" };
      if (current.dir === "asc") return { key, dir: "desc" };
      return null;
    });
  }

  return (
    <div className="flex flex-col gap-4">
      {pastDueCount > 0 && (
        <p
          role="status"
          className="flex items-center gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground"
        >
          <span className="inline-block h-2 w-2 shrink-0 rounded-full bg-warning" aria-hidden="true" />
          {pastDueCount} {pastDueCount === 1 ? "tenant is" : "tenants are"} past due right now.{" "}
          <button
            type="button"
            onClick={() => setStatusFilter("past_due")}
            className="rounded font-medium underline underline-offset-4 hover:no-underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
          >
            Show them
          </button>
        </p>
      )}

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <label className="flex-1 sm:max-w-xs">
          <span className="sr-only">Search tenants by name</span>
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search by name…"
            className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
        </label>

        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-sm text-muted-foreground">
            Status
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as StatusFilter)}
              className="h-9 rounded-lg border border-border bg-background px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            >
              {STATUS_FILTERS.map((status) => (
                <option key={status} value={status}>
                  {status === "all" ? "All statuses" : STATUS_LABELS[status]}
                </option>
              ))}
            </select>
          </label>

          <div role="radiogroup" aria-label="Filter by type" className="flex gap-1 rounded-lg border border-border p-1">
            {TYPE_FILTERS.map((type) => (
              <button
                key={type}
                type="button"
                aria-pressed={typeFilter === type}
                onClick={() => setTypeFilter(type)}
                className={`rounded-md px-3 py-1 text-sm font-medium capitalize transition-colors focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 ${
                  typeFilter === type
                    ? "bg-primary text-primary-foreground"
                    : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {type}
              </button>
            ))}
          </div>
        </div>
      </div>

      {filtered.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          {tenants.length === 0 ? "No tenants yet." : "No tenants match your search or filters."}
        </p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">All tenants across every account, with current billing state</caption>
            <thead>
              <tr className="border-b border-border bg-muted/50 text-muted-foreground">
                <SortableHeader label="Name" sortKey="name" sort={sort} onSort={toggleSort} />
                <th scope="col" className="px-4 py-2 font-medium">
                  Type
                </th>
                <th scope="col" className="px-4 py-2 font-medium">
                  Plan
                </th>
                <SortableHeader label="Status" sortKey="status" sort={sort} onSort={toggleSort} />
                <SortableHeader label="Next renewal" sortKey="next_renewal_date" sort={sort} onSort={toggleSort} />
                <th scope="col" className="px-4 py-2 font-medium">
                  Auto-renew
                </th>
                <SortableHeader label="Created" sortKey="created_at" sort={sort} onSort={toggleSort} />
                <th scope="col" className="px-4 py-2 font-medium">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((tenant) => {
                const isPastDue = tenant.status === "past_due";

                return (
                  <tr
                    key={tenant.id}
                    className={`border-b border-border last:border-0 ${isPastDue ? "bg-warning/5" : ""}`}
                  >
                    <td className="px-4 py-2 font-medium text-foreground">
                      <span className="flex items-center gap-2">
                        {isPastDue && (
                          <span
                            className="inline-block h-2 w-2 shrink-0 rounded-full bg-warning"
                            title="Past due — payment failed, Paddle is retrying"
                            aria-hidden="true"
                          />
                        )}
                        {tenant.name}
                        {isPastDue && <span className="sr-only"> — past due</span>}
                      </span>
                    </td>
                    <td className="px-4 py-2 capitalize text-muted-foreground">{tenant.type}</td>
                    <td className="px-4 py-2 text-muted-foreground">
                      {tenant.billing_interval ? INTERVAL_LABELS[tenant.billing_interval] : "—"}
                    </td>
                    <td className="px-4 py-2">
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_PILL_CLASSES[tenant.status]}`}
                      >
                        {STATUS_LABELS[tenant.status]}
                      </span>
                    </td>
                    <td className="px-4 py-2 text-muted-foreground">
                      {tenant.next_renewal_date ? (
                        <time dateTime={tenant.next_renewal_date} className="font-mono text-xs">
                          {new Date(tenant.next_renewal_date).toLocaleDateString()}
                        </time>
                      ) : tenant.status === "trialing" && tenant.trial_ends_at ? (
                        <span className="text-xs">Trial ends {new Date(tenant.trial_ends_at).toLocaleDateString()}</span>
                      ) : (
                        "—"
                      )}
                    </td>
                    <td className="px-4 py-2 text-muted-foreground">
                      {tenant.auto_renew === null ? "—" : tenant.auto_renew ? "On" : "Off"}
                    </td>
                    <td className="px-4 py-2 text-muted-foreground">
                      <time dateTime={tenant.created_at} className="font-mono text-xs">
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
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function sortValue(tenant: Tenant, key: SortKey): string | null {
  switch (key) {
    case "name":
      return tenant.name.toLowerCase();
    case "status":
      return tenant.status;
    case "next_renewal_date":
      return tenant.next_renewal_date ?? tenant.trial_ends_at;
    case "created_at":
      return tenant.created_at;
  }
}

function SortableHeader({
  label,
  sortKey,
  sort,
  onSort,
}: {
  label: string;
  sortKey: SortKey;
  sort: { key: SortKey; dir: "asc" | "desc" } | null;
  onSort: (key: SortKey) => void;
}) {
  const active = sort?.key === sortKey;

  return (
    <th scope="col" className="px-4 py-2 font-medium" aria-sort={active ? (sort.dir === "asc" ? "ascending" : "descending") : "none"}>
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className="flex items-center gap-1 rounded font-medium text-inherit hover:text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
      >
        {label}
        <span aria-hidden="true" className="text-xs">
          {active ? (sort.dir === "asc" ? "↑" : "↓") : ""}
        </span>
      </button>
    </th>
  );
}
