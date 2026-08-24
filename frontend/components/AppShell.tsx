import { apiFetch } from "@/lib/api";
import { getIsAdmin } from "@/lib/session";
import type { OnboardingStatus } from "@/app/(app)/onboarding/actions";
import type { Tenant } from "@/app/(app)/settings/actions";
import type { TourStatus } from "@/lib/tours";
import { SidebarShell } from "./SidebarShell";

/**
 * .claude/DESIGN.md app shell: 260px (16.25rem) sidebar wrapping every
 * authenticated screen. Server Component so it can read the is_admin
 * cookie hint server-side (same convention as the old AppNav) before
 * handing off to the client-side sidebar for collapse/active-state.
 *
 * `permissions` below drives SidebarShell's nav gating (owner/member
 * two-tier structure — separate from isAdmin/the cross-tenant admin
 * system). Read fresh from /tenant on every navigation, same as
 * trialExpired below — never cached in a login-time cookie the way
 * isAdmin is, because a permission an owner just revoked has to stop
 * showing in the nav on this member's very next page load, not just
 * after they log back in. An owner's own permissions object already
 * reads all-true from the API (TenantController's payload), so no
 * separate isOwner prop is needed here — the same permissions check
 * correctly shows everything for an owner too.
 *
 * Also fetches /tenant for the trial-expired banner (decision doc:
 * "Show a clear, honest banner/message... rather than a silent block or
 * a confusing error") — every authenticated screen goes through this
 * shell, so this is the one place that guarantees the banner shows up
 * everywhere a trial_expired tenant can still navigate to (dashboard,
 * contacts, templates, reviews, analytics, settings), not just the
 * screens that happen to hit a gated endpoint. A failed fetch here fails
 * silent (no banner) rather than blocking the whole shell from
 * rendering — this is a secondary, informational read, not the actual
 * access gate (RequireSendingAccess, enforced server-side regardless of
 * whether this banner renders). The same /tenant response also carries
 * renewal_reminder (.claude/BILLING.md "Renewal reminders") — the
 * dashboard-banner counterpart to billing:send-renewal-reminders' emails,
 * same "every authenticated screen, not just one page" reasoning — and
 * sending_blocked_reason, which drives the "add email verification"
 * decision doc's own banner the same way.
 *
 * Also fetches GET /tours/status directly (same pattern as /tenant
 * above — this is already an async Server Component, so no separate
 * lib/tours.ts action needed for the read, only for the writes
 * SidebarShell performs). Server-side and per-user, not localStorage
 * (see the has_completed_welcome_tour/tours_seen migration's own
 * docblock): a device switch or cleared browser data must never
 * re-trigger a tour the user already completed.
 *
 * And GET /onboarding/status, purely to compute hasContacts/gbpConnected
 * for WelcomeTour's conditional steps — SidebarShell needs to know
 * whether app/(app)/dashboard/page.tsx will render the KPI strip or the
 * "get started" prompt (they're mutually exclusive) before it can decide
 * which step to point at, since it can't inspect dashboard's own render
 * output from here.
 */
export async function AppShell({ children }: { children: React.ReactNode }) {
  const isAdmin = await getIsAdmin();
  const [tenantResult, tourResult, onboardingResult] = await Promise.all([
    apiFetch<Tenant>("/tenant"),
    apiFetch<TourStatus>("/tours/status"),
    apiFetch<OnboardingStatus>("/onboarding/status"),
  ]);
  const trialExpired = tenantResult.ok && tenantResult.data.status === "trial_expired";
  // Distinct from trialExpired: past_due does NOT block sending
  // (Tenant::sendingBlockedReason() has no branch for it —
  // .claude/BILLING.md's dunning design), so this is an informational
  // notice, not a blocking one. Mutually exclusive with trialExpired by
  // construction (tenant.status is one value), but kept as its own
  // independent slot below rather than an if/else, same reasoning as
  // every other banner flag here.
  const pastDue = tenantResult.ok && tenantResult.data.status === "past_due";
  // Same "fail silent, no banner" reasoning as trialExpired above — a
  // transient /tenant fetch error should never surface a spurious
  // "verify your email" nudge, and the real gate (RequireSendingAccess)
  // doesn't depend on this rendering either.
  const emailUnverified = tenantResult.ok && tenantResult.data.sending_blocked_reason === "email_unverified";
  // Same "fail silent, no banner" reasoning as trialExpired above — a
  // transient /tenant fetch error should never surface a spurious
  // renewal nudge, and the real gate (RequireSendingAccess) doesn't
  // depend on this rendering either.
  const renewalReminder = tenantResult.ok ? tenantResult.data.renewal_reminder : null;
  // A failed /tenant fetch defaults to full access, never the reverse —
  // hiding nav items on a transient fetch error would lock a real owner
  // out of their own sidebar. The actual enforcement is server-side
  // regardless (RequirePermission/EnsureTenantOwner on every route), so
  // the worst case here is a nav item shown that 403s on click, not a
  // silent, unrecoverable UI lockout.
  const permissions = tenantResult.ok
    ? tenantResult.data.permissions
    : { contacts: true, templates: true, reviews: true, analytics: true };
  // A failed fetch defaults to "already completed" (never auto-launch)
  // rather than "not completed" — a transient API error should never
  // spuriously trigger an unwanted tour; the manual "Take a tour" link
  // is always available regardless.
  const hasCompletedWelcomeTour = !tourResult.ok || tourResult.data.has_completed_welcome_tour;
  // Same failure-mode reasoning: if this read fails, default to the
  // "everything's set up" branch — a spurious "one thing left" nudge
  // pointed at a target that may not even exist is worse than the tour
  // just skipping that step silently.
  const hasContacts = onboardingResult.ok && onboardingResult.data.contacts_count > 0;
  const gbpConnected = onboardingResult.ok && onboardingResult.data.gbp_status === "connected";

  return (
    <SidebarShell
      isAdmin={isAdmin}
      permissions={permissions}
      trialExpired={trialExpired}
      pastDue={pastDue}
      emailUnverified={emailUnverified}
      renewalReminder={renewalReminder}
      hasCompletedWelcomeTour={hasCompletedWelcomeTour}
      hasContacts={hasContacts}
      gbpConnected={gbpConnected}
    >
      {children}
    </SidebarShell>
  );
}
