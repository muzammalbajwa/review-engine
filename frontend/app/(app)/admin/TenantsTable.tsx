"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

type Tenant = {
  id: string;
  name: string;
  type: "admin" | "customer";
  created_at: string;
};

const TYPE_FILTERS = ["all", "admin", "customer"] as const;
type TypeFilter = (typeof TYPE_FILTERS)[number];

/**
 * .claude/DESIGN.md dashboard-pattern guidance: search/filter lives above
 * the table as visible controls, not behind a menu. The full tenant list
 * is fetched once server-side (GET /admin/tenants has no search params of
 * its own — dozens of tenants, not thousands, so filtering client-side
 * here is the right tradeoff over adding server-side query params for a
 * dataset this size).
 */
export function TenantsTable({ tenants }: { tenants: Tenant[] }) {
  const [query, setQuery] = useState("");
  const [typeFilter, setTypeFilter] = useState<TypeFilter>("all");

  const filtered = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();

    return tenants.filter((tenant) => {
      if (typeFilter !== "all" && tenant.type !== typeFilter) {
        return false;
      }
      if (normalizedQuery !== "" && !tenant.name.toLowerCase().includes(normalizedQuery)) {
        return false;
      }
      return true;
    });
  }, [tenants, query, typeFilter]);

  return (
    <div className="flex flex-col gap-4">
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

      {filtered.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          {tenants.length === 0 ? "No tenants yet." : "No tenants match your search."}
        </p>
      ) : (
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
              {filtered.map((tenant) => (
                <tr key={tenant.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-2 font-medium text-foreground">{tenant.name}</td>
                  <td className="px-4 py-2 capitalize text-muted-foreground">{tenant.type}</td>
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
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
