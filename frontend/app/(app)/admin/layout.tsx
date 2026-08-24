import { Eye } from "lucide-react";
import Link from "next/link";

import { AdminSidebarNav } from "@/components/admin/AdminSidebarNav";

/**
 * .claude/DESIGN.md color rule: red is reserved for compliance-blocked
 * states and real errors. Being in the admin area is neither — it's
 * informational — so this uses a neutral, dark, inverted treatment
 * (bg-foreground/text-background) instead, with the moss primary reserved
 * for the one thing that IS an accent here (active nav, the icon).
 *
 * Persistent means every /admin/* screen gets this from the layout, not
 * from each page choosing to render it.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col">
      <div className="flex items-center justify-between gap-4 bg-foreground px-4 py-2.5 text-background">
        <div className="flex items-center gap-2 text-sm">
          <Eye className="size-4 shrink-0 text-primary" aria-hidden="true" />
          <p>
            <span className="font-medium">Admin — read-only view.</span>{" "}
            <span className="text-background/70">Every tenant record you open here is logged.</span>
          </p>
        </div>
        <Link
          href="/dashboard"
          className="shrink-0 rounded text-sm font-medium text-background/70 underline-offset-4 hover:text-background hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          Back to my dashboard
        </Link>
      </div>

      <div className="flex flex-1 flex-col md:flex-row">
        <AdminSidebarNav />
        <div className="min-w-0 flex-1 bg-background">{children}</div>
      </div>
    </div>
  );
}
