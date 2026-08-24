"use client";

import { Activity, ScrollText, Users } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ComponentType } from "react";

import { cn } from "@/lib/utils";

const ADMIN_NAV_ITEMS: { href: string; label: string; icon: ComponentType<{ className?: string }> }[] = [
  { href: "/admin", label: "Tenants", icon: Users },
  { href: "/admin/audit-log", label: "Audit log", icon: ScrollText },
  { href: "/admin/system", label: "System", icon: Activity },
];

/**
 * Deliberately styled nothing like SidebarShell (the tenant app's nav):
 * dark/inverted (bg-foreground/text-background) instead of the tenant
 * app's white --sidebar, so switching between "my own admin tools" and "a
 * tenant's actual account" is never a matter of reading text closely.
 */
export function AdminSidebarNav() {
  const pathname = usePathname();

  return (
    <nav
      aria-label="Admin"
      className="flex shrink-0 gap-1 overflow-x-auto border-b border-background/10 bg-foreground px-3 py-2 text-background md:w-[16.25rem] md:flex-col md:overflow-visible md:border-b-0 md:border-r md:px-3 md:py-4"
    >
      {ADMIN_NAV_ITEMS.map((item) => {
        // "/admin" needs its own rule: a plain prefix match would also
        // catch "/admin/audit-log" and "/admin/system" (both literally
        // start with "/admin/"), and the tenant drill-in
        // ("/admin/tenants/[id]") is the one route that should keep
        // "Tenants" highlighted despite not being under item.href exactly.
        const active =
          item.href === "/admin"
            ? pathname === "/admin" || pathname.startsWith("/admin/tenants")
            : pathname === item.href || pathname.startsWith(`${item.href}/`);
        const Icon = item.icon;

        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex shrink-0 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50",
              active
                ? "bg-primary text-primary-foreground"
                : "text-background/70 hover:bg-background/10 hover:text-background",
            )}
          >
            <Icon className="size-4.5 shrink-0" />
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}
