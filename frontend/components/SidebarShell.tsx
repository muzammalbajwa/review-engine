"use client";

import {
  BarChart3,
  BookOpen,
  Compass,
  FileText,
  LayoutDashboard,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  Settings as SettingsIcon,
  ShieldCheck,
  Star,
  Users,
  X,
} from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ComponentType } from "react";

import { logout, resendVerificationEmail, type Permissions, type Tenant } from "@/app/(app)/settings/actions";
import { markWelcomeTourSeen } from "@/lib/tours";
import { cn } from "@/lib/utils";
import { WelcomeTour } from "./WelcomeTour";

type NavItemDef = {
  href: string;
  label: string;
  icon: ComponentType<{ className?: string }>;
  // Defaults to `href` itself. "Contacts" needs a broader match: its own
  // destination is /contacts/import, but /contacts/quick-add is equally
  // "Contacts" and should stay highlighted there too — same fix as
  // AdminSidebarNav's own "/admin" vs "/admin/tenants/[id]" case.
  activePrefix?: string;
  // DashboardTour's steps target these by [data-tour="nav-{tourId}"] —
  // kept as an explicit id (not derived from href) so the tour's step
  // list and this nav list can never silently drift apart from an
  // unrelated href change. Omitted entirely for nav items the tour
  // doesn't cover (Admin).
  tourId?: string;
  // Omitted for items every team member can always reach (Dashboard,
  // Settings) regardless of role — present for the four resources the
  // owner/member permission system actually gates. This is a UI
  // convenience only: the real enforcement is server-side
  // (RequirePermission on every one of these routes) regardless of
  // whether this hides the link.
  permissionKey?: keyof Permissions;
};

const NAV_ITEMS: NavItemDef[] = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard, tourId: "dashboard" },
  {
    href: "/contacts/import",
    label: "Contacts",
    icon: Users,
    activePrefix: "/contacts",
    tourId: "contacts",
    permissionKey: "contacts",
  },
  { href: "/templates", label: "Templates", icon: FileText, tourId: "templates", permissionKey: "templates" },
  { href: "/reviews", label: "Reviews", icon: Star, tourId: "reviews", permissionKey: "reviews" },
  { href: "/analytics", label: "Analytics", icon: BarChart3, tourId: "analytics", permissionKey: "analytics" },
  { href: "/settings", label: "Settings", icon: SettingsIcon, tourId: "settings" },
  // No permissionKey — documentation, not a tenant-scoped resource, so
  // every role sees it same as Dashboard/Settings above.
  { href: "/docs/api", label: "API Docs", icon: BookOpen },
];

// sessionStorage, not a localStorage/DB flag — this is a one-shot "the
// user just clicked Take a tour from elsewhere, continue that after
// navigating" signal, not the "has this tour been seen" record (that's
// server-side, per-user — see hasCompletedWelcomeTour). Cleared the
// instant it's read (handleTakeTourClick sets it, the effect below
// reads-and-removes it), so it can never survive to spuriously fire on
// some later, unrelated /dashboard visit.
const MANUAL_START_KEY = "re_welcome_tour_manual_start_pending";

/**
 * .claude/DESIGN.md: "App shell: 260px sidebar... 16.25rem as its width."
 */
export function SidebarShell({
  isAdmin,
  permissions,
  trialExpired,
  emailUnverified,
  renewalReminder,
  hasCompletedWelcomeTour,
  hasContacts,
  gbpConnected,
  children,
}: {
  isAdmin: boolean;
  // Owner/member two-tier structure — an owner's own permissions object
  // already reads all-true (TenantController's payload), so filtering
  // NAV_ITEMS against this alone correctly shows everything for both
  // roles, no separate isOwner check needed here.
  permissions: Permissions;
  trialExpired: boolean;
  // The "add email verification" decision doc: true whenever
  // sending_blocked_reason === "email_unverified" — mutually exclusive
  // with trialExpired by construction (Tenant::sendingBlockedReason()
  // checks trial/subscription status before ever reaching the email
  // check), so only one of the two banners below ever renders at once.
  emailUnverified: boolean;
  // Mutually exclusive with trialExpired by construction (TenantController
  // only computes this when status === "active") — both slots below are
  // still independent rather than an if/else, so that never has to be
  // relied on for correctness.
  renewalReminder: Tenant["renewal_reminder"];
  // Server-side, per-user (2026_08_10_120000_add_tour_progress_to_users_
  // table) — not localStorage. A device switch or cleared browser data
  // must never re-trigger a tour this user already completed, and a
  // tenant with multiple team members needs each person's own state,
  // not one shared per-browser flag.
  hasCompletedWelcomeTour: boolean;
  // Drives WelcomeTour's conditional steps — see AppShell's own
  // docblock for why these come from here rather than being derived
  // locally (the targets they gate live on the dashboard page, which
  // this component doesn't render).
  hasContacts: boolean;
  gbpConnected: boolean;
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const isDashboard = pathname === "/dashboard";
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [tourRun, setTourRun] = useState(false);

  // Closing the mobile drawer on navigation is a render-time state
  // adjustment (React's documented alternative to an effect for "reset
  // state when a prop changes"), not a side effect — so it belongs here,
  // not in a useEffect.
  const [lastPathname, setLastPathname] = useState(pathname);
  if (pathname !== lastPathname) {
    setLastPathname(pathname);
    setMobileOpen(false);
  }

  // Auto-starts once, the first time this user ever lands on /dashboard
  // without having completed the tour — OR immediately, if this mount
  // is the landing after a manual "Take a tour" click from some other
  // page (see handleTakeTourClick/MANUAL_START_KEY below).
  //
  // sessionStorage, not component state: there's no shared layout.tsx
  // under app/(app)/ — every page.tsx wraps itself in its own <AppShell>
  // independently, so navigating from e.g. /settings to /dashboard fully
  // unmounts this component and mounts a brand new instance. A "please
  // start the tour once you land" flag set as component state before
  // calling router.push() would be destroyed by that unmount before it
  // could ever be read — confirmed live (the first cut of this used a
  // pendingManualStart useState + router.push(), and the tour silently
  // never started on arrival). sessionStorage survives across that
  // remount since it isn't tied to this component's lifecycle at all.
  useEffect(() => {
    if (!isDashboard) {
      return;
    }

    const manualStartPending = window.sessionStorage.getItem(MANUAL_START_KEY) === "1";

    if (manualStartPending) {
      window.sessionStorage.removeItem(MANUAL_START_KEY);
      startTour();
      return;
    }

    if (!hasCompletedWelcomeTour) {
      startTour();
    }
  }, [isDashboard, hasCompletedWelcomeTour]);

  function toggleCollapsed() {
    setCollapsed((prev) => !prev);
  }

  // Forces the sidebar into a state where every nav item is actually on
  // screen before the tour starts — on mobile the drawer is
  // translate-x-full (off canvas) until opened, and collapsed desktop
  // mode hides the text labels Joyride's tooltips are anchored next to.
  function startTour() {
    setMobileOpen(true);
    setCollapsed(false);
    setTourRun(true);
  }

  // The "Take a tour" link's own click handler — WelcomeTour's later
  // steps only make sense (and only have real targets) on /dashboard,
  // so a click from anywhere else navigates there first and defers
  // actually starting the tour to the sessionStorage check in the effect
  // above (see its docblock for why this can't just be component state).
  function handleTakeTourClick() {
    if (isDashboard) {
      startTour();
      return;
    }

    window.sessionStorage.setItem(MANUAL_START_KEY, "1");
    router.push("/dashboard");
  }

  // router.refresh() re-runs AppShell (a Server Component) so its next
  // GET /tours/status read comes back with has_completed_welcome_tour
  // now true — without this, the prop stays stale for the rest of the
  // session and the auto-launch effect above would fire again on a
  // later /dashboard revisit (its dependency array only re-evaluates
  // when hasCompletedWelcomeTour actually changes).
  async function finishTour() {
    setTourRun(false);
    setMobileOpen(false);
    await markWelcomeTourSeen();
    router.refresh();
  }

  return (
    <div className="flex min-h-screen flex-col md:flex-row">
      <header className="flex items-center justify-between border-b border-border bg-sidebar px-4 py-3 md:hidden">
        <button
          type="button"
          onClick={() => setMobileOpen(true)}
          aria-label="Open menu"
          className="rounded-lg p-1.5 text-sidebar-foreground hover:bg-sidebar-accent focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          <Menu className="size-5" />
        </button>
        <span className="font-heading text-sm font-semibold text-sidebar-foreground">ReviewEngine</span>
        <span className="size-5" />
      </header>

      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/40 md:hidden"
          onClick={() => setMobileOpen(false)}
          aria-hidden="true"
        />
      )}

      <aside
        aria-label="Main"
        className={cn(
          "z-50 flex w-[16.25rem] shrink-0 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-transform duration-200 md:sticky md:top-0 md:h-screen md:translate-x-0",
          "fixed inset-y-0 left-0 md:static",
          mobileOpen ? "translate-x-0" : "-translate-x-full",
          collapsed && "md:w-[4.5rem]",
        )}
      >
        <div className="flex items-center justify-between p-4">
          {!collapsed && <span className="font-heading text-sm font-semibold">ReviewEngine</span>}
          <button
            type="button"
            onClick={() => setMobileOpen(false)}
            aria-label="Close menu"
            className="rounded-lg p-1.5 hover:bg-sidebar-accent focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 md:hidden"
          >
            <X className="size-5" />
          </button>
          <button
            type="button"
            onClick={toggleCollapsed}
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            className="hidden rounded-lg p-1.5 hover:bg-sidebar-accent focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 md:block"
          >
            {collapsed ? <PanelLeftOpen className="size-4" /> : <PanelLeftClose className="size-4" />}
          </button>
        </div>

        <nav aria-label="Primary" data-tour="sidebar-nav" className="flex flex-1 flex-col gap-1 px-3 py-2">
          {NAV_ITEMS.filter((item) => !item.permissionKey || permissions[item.permissionKey]).map((item) => (
            <NavItem key={item.href} item={item} pathname={pathname} collapsed={collapsed} />
          ))}
          {isAdmin && (
            <>
              <div className="my-2 border-t border-sidebar-border" />
              <NavItem
                item={{ href: "/admin", label: "Admin", icon: ShieldCheck }}
                pathname={pathname}
                collapsed={collapsed}
              />
            </>
          )}
        </nav>

        <div className="border-t border-sidebar-border p-3">
          <button
            type="button"
            onClick={handleTakeTourClick}
            title={collapsed ? "Take the tour again" : undefined}
            className={cn(
              "flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50",
              collapsed && "md:justify-center md:px-0",
            )}
          >
            <Compass className="size-4.5 shrink-0" />
            <span className={cn(collapsed && "md:hidden")}>Take the tour again</span>
          </button>
          <form action={logout}>
            <button
              type="submit"
              title={collapsed ? "Log out" : undefined}
              className={cn(
                "flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50",
                collapsed && "md:justify-center md:px-0",
              )}
            >
              <LogOut className="size-4.5 shrink-0" />
              <span className={cn(collapsed && "md:hidden")}>Log out</span>
            </button>
          </form>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        {trialExpired && <TrialExpiredBanner />}
        {emailUnverified && <EmailUnverifiedBanner />}
        {renewalReminder && <RenewalReminderBanner reminder={renewalReminder} />}
        <div className="min-w-0 flex-1">{children}</div>
      </div>

      <WelcomeTour run={tourRun} onFinish={finishTour} hasContacts={hasContacts} gbpConnected={gbpConnected} />
    </div>
  );
}

/**
 * "cannot send new review requests, use quick-add, or hit the webhook
 * API. Show a clear, honest banner/message... rather than a silent
 * block or a confusing error." Same destructive-tinted style
 * SectionError (settings/page.tsx) already uses for a real, blocking
 * error state — this is that, not a dismissible/decorative notice
 * (.claude/DESIGN.md: destructive red is reserved for "an actual
 * compliance-blocked state or a real error," and a stopped send is
 * exactly that).
 */
function TrialExpiredBanner() {
  return (
    <div
      role="alert"
      className="flex flex-wrap items-center justify-between gap-3 border-b border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
    >
      <span>Your trial ended — subscribe to keep sending review requests.</span>
      <Link
        href="/settings?tab=billing"
        className="shrink-0 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/50"
      >
        Subscribe now
      </Link>
    </div>
  );
}

/**
 * The "add email verification" decision doc: same destructive/red
 * treatment as TrialExpiredBanner, not the gold/warning tint
 * RenewalReminderBanner uses — sending is actually blocked right now,
 * not just an advance notice, so DESIGN.md's "destructive red... an
 * actual compliance-blocked state or a real error" rule puts this in the
 * same bucket as the trial banner, not the renewal one. The resend
 * button lives directly in the banner (not just in Settings) so it's the
 * "visible... action" requirement is met from every authenticated
 * screen, the same "visible from login" reach TrialExpiredBanner already
 * has — not something a blocked owner has to go hunting for.
 */
function EmailUnverifiedBanner() {
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState<{ message: string; isError: boolean } | null>(null);

  async function handleResend() {
    setPending(true);
    setResult(null);

    const response = await resendVerificationEmail();

    setPending(false);
    setResult({ message: response.message, isError: response.status === "error" });
  }

  return (
    <div
      role="alert"
      className="flex flex-wrap items-center justify-between gap-3 border-b border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
    >
      <span>Verify your email to start sending review requests.</span>
      <div className="flex shrink-0 items-center gap-3">
        {result && <span className={result.isError ? "text-destructive" : "text-muted-foreground"}>{result.message}</span>}
        <button
          type="button"
          onClick={handleResend}
          disabled={pending}
          className="rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60"
        >
          {pending ? "Sending…" : "Resend verification email"}
        </button>
      </div>
    </div>
  );
}

/**
 * .claude/BILLING.md "Renewal reminders" — the in-app counterpart to
 * billing:send-renewal-reminders' emails, "visible from login, not just
 * the email." Gold/warning, not destructive red: unlike TrialExpiredBanner
 * (an actual block), nothing has stopped here — this only ever renders
 * while status is still "active" (TenantController's own gate), so
 * DESIGN.md's "destructive red... nowhere except an actual
 * compliance-blocked state or a real error" rule keeps this out of that
 * color. role="status" (not "alert") for the same reason — informational,
 * not an error condition.
 */
function RenewalReminderBanner({ reminder }: { reminder: NonNullable<Tenant["renewal_reminder"]> }) {
  const date = new Date(reminder.period_end).toLocaleDateString();
  const message = reminder.auto_renew
    ? `Your card will be charged ${reminder.amount_display ?? "the plan price"} on ${date} for your ${reminder.billing_interval ?? ""} plan.`
    : `Your subscription ends on ${date} — renew now to keep sending review requests.`;

  return (
    <div
      role="status"
      className="flex flex-wrap items-center justify-between gap-3 border-b border-warning/40 bg-warning/10 px-4 py-3 text-sm text-foreground"
    >
      <span>{message}</span>
      <Link
        href="/settings?tab=billing"
        className="shrink-0 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/50"
      >
        {reminder.auto_renew ? "Manage billing" : "Renew now"}
      </Link>
    </div>
  );
}

function NavItem({
  item,
  pathname,
  collapsed,
}: {
  item: NavItemDef;
  pathname: string;
  collapsed: boolean;
}) {
  const matchAgainst = item.activePrefix ?? item.href;
  const active = pathname === matchAgainst || pathname.startsWith(`${matchAgainst}/`);
  const Icon = item.icon;

  return (
    <Link
      href={item.href}
      title={collapsed ? item.label : undefined}
      aria-current={active ? "page" : undefined}
      data-tour={item.tourId ? `nav-${item.tourId}` : undefined}
      className={cn(
        "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50",
        collapsed && "md:justify-center md:px-0",
        active
          ? "bg-sidebar-primary text-sidebar-primary-foreground"
          : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
      )}
    >
      <Icon className="size-4.5 shrink-0" />
      <span className={cn(collapsed && "md:hidden")}>{item.label}</span>
    </Link>
  );
}
