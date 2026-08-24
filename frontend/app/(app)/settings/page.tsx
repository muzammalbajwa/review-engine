import { AppShell } from "@/components/AppShell";
import { GbpConnectionCard } from "@/components/gbp/GbpConnectionCard";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { GbpStatus } from "../gbp/actions";
import { ApiKeysSection } from "./ApiKeysSection";
import { BusinessProfileSection } from "./BusinessProfileSection";
import { IntegrationLinksSection } from "./IntegrationLinksSection";
import { QuickAddLinkSection } from "./QuickAddLinkSection";
import { SenderIdentitiesSection } from "./SenderIdentitiesSection";
import { SettingsTabs } from "./SettingsTabs";
import { TeamSection } from "./TeamSection";
import { WebhookActivitySection } from "./WebhookActivitySection";
import type { ApiKey, SenderIdentity, Team, Tenant, WebhookActivity } from "./actions";

/**
 * .claude/DESIGN.md: likely the screen a tenant lands on when something's
 * wrong (a billing issue, a disconnected integration) — bias toward
 * clarity and calm over density. One tab visible at a time, each section
 * fails independently (a broken GBP fetch shouldn't also hide billing,
 * which might be exactly what the tenant came here to fix).
 */
export default async function SettingsPage() {
  await requireToken();

  // /tenant carries the calling user's own role/is_owner/permissions
  // (TenantController's payload) — fetched first, not in the Promise.all
  // below, so a member's browser never even attempts the owner-only
  // /subscription and /team calls that would just 403 (EnsureTenantOwner).
  const tenantResult = await apiFetch<Tenant>("/tenant");
  const isOwner = tenantResult.ok && tenantResult.data.is_owner;

  const [teamResult, gbpResult, sendersResult, apiKeysResult, webhookActivityResult] =
    await Promise.all([
      isOwner ? apiFetch<Team>("/team") : Promise.resolve(null),
      apiFetch<GbpStatus>("/gbp/status"),
      apiFetch<SenderIdentity[]>("/sender-identities"),
      apiFetch<ApiKey[]>("/api-keys"),
      apiFetch<WebhookActivity>("/contacts/webhook-activity"),
    ]);

  const webhookUrl = `${process.env.NEXT_PUBLIC_API_URL}/api/v1/contacts`;

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-2xl">
          <h1 className="mb-6 text-lg font-semibold">Settings</h1>

          <SettingsTabs
            isOwner={isOwner}
            business={
              tenantResult.ok ? (
                <div className="flex flex-col gap-6">
                  <BusinessProfileSection tenant={tenantResult.data} />
                  <QuickAddLinkSection token={tenantResult.data.quick_add_token} />
                </div>
              ) : (
                <SectionError message={tenantResult.message} status={tenantResult.status} />
              )
            }
            sender={
              sendersResult.ok ? (
                <SenderIdentitiesSection initialIdentities={sendersResult.data} />
              ) : (
                <SectionError message={sendersResult.message} status={sendersResult.status} />
              )
            }
            gbp={
              gbpResult.ok ? (
                <GbpConnectionCard status={gbpResult.data} />
              ) : (
                <SectionError message={gbpResult.message} status={gbpResult.status} />
              )
            }
            billing={
              // isOwner already implies tenantResult.ok (isOwner = tenantResult.ok
              // && tenantResult.data.is_owner above) — no separate error branch
              // needed here; a failed /tenant fetch is handled once, by the
              // "business" tab's own SectionError above.
              isOwner ? <BillingPlaceholder plan={tenantResult.data.plan} status={tenantResult.data.status} /> : undefined
            }
            team={
              isOwner && teamResult
                ? teamResult.ok
                  ? <TeamSection initialTeam={teamResult.data} />
                  : <SectionError message={teamResult.message} status={teamResult.status} />
                : undefined
            }
            apiKeys={
              apiKeysResult.ok ? (
                <div className="flex flex-col gap-8">
                  <ApiKeysSection initialKeys={apiKeysResult.data} />
                  {webhookActivityResult.ok ? (
                    <WebhookActivitySection webhookUrl={webhookUrl} initialActivity={webhookActivityResult.data} />
                  ) : (
                    <SectionError message={webhookActivityResult.message} status={webhookActivityResult.status} />
                  )}
                  <IntegrationLinksSection />
                </div>
              ) : (
                <SectionError message={apiKeysResult.message} status={apiKeysResult.status} />
              )
            }
          />
        </div>
      </main>
    </AppShell>
  );
}

// BillingSection (checkout, auto-renew toggle, billing portal link) was
// removed with the Lemon Squeezy package — those actions all depended on
// a live processor integration that no longer exists. This is a
// read-only stand-in using the same plan/status fields /tenant already
// carries (provider-agnostic — see Tenant::sendingBlocked()'s state
// machine) until a Paddle-backed BillingSection is rebuilt.
const BILLING_STATUS_LABELS: Record<Tenant["status"], string> = {
  pending: "No plan yet",
  trialing: "Free trial",
  active: "Active",
  trial_expired: "Trial expired",
  canceled: "Canceled",
};

function BillingPlaceholder({ plan, status }: { plan: string | null; status: Tenant["status"] }) {
  return (
    <div className="flex flex-col gap-3">
      <div>
        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Current plan</p>
        <p className="mt-2 text-sm text-foreground">
          <span className="text-base font-semibold">{plan ?? "Plan"}</span>{" "}
          <span className="text-muted-foreground">— {BILLING_STATUS_LABELS[status]}</span>
        </p>
      </div>
      <p className="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
        Billing management is temporarily unavailable while we switch payment providers. Your plan and access are
        unaffected — check back soon to manage your subscription here.
      </p>
    </div>
  );
}

function SectionError({ message, status }: { message: string; status: number }) {
  const text = status === 401 ? "Your session has expired. Log in again to continue." : message;

  return (
    <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
      {text}
    </p>
  );
}
