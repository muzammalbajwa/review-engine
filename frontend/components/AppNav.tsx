import Link from "next/link";

import { getIsAdmin } from "@/lib/session";

/**
 * .claude/FRONTEND.md: shared nav across the authenticated screens. Admin
 * link is a UI convenience only (from the is_admin cookie hint) — the real
 * gate is server-side (TenantPolicy::viewAny) on every /admin/* request, so
 * a stale/spoofed hint here can only ever show a link that then 403s, never
 * grant access.
 */
export async function AppNav() {
  const isAdmin = await getIsAdmin();

  return (
    <nav
      aria-label="Main"
      className="mb-6 flex items-center gap-4 border-b border-border pb-4 text-sm"
    >
      <Link
        href="/contacts/import"
        className="rounded font-medium text-foreground hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
      >
        Import contacts
      </Link>
      <Link
        href="/templates"
        className="rounded font-medium text-foreground hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
      >
        Templates
      </Link>
      {isAdmin && (
        <Link
          href="/admin"
          className="rounded font-medium text-foreground hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          Admin
        </Link>
      )}
    </nav>
  );
}
