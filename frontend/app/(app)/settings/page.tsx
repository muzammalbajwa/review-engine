import { AppShell } from "@/components/AppShell";
import { GbpConnectionCard } from "@/components/gbp/GbpConnectionCard";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { GbpStatus } from "../gbp/actions";
import { ApiKeysSection } from "./ApiKeysSection";
import { BillingSection } from "./BillingSection";
import { BusinessProfileSection } from "./BusinessProfileSection";
import { IntegrationLinksSection } from "./IntegrationLinksSection";
import { QuickAddLinkSection } from "./QuickAddLinkSection";
import { SenderIdentitiesSection } from "./SenderIdentitiesSection";
import { SettingsTabs } from "./SettingsTabs";
import { TeamSection } from "./TeamSection";
import { WebhookActivitySection } from "./WebhookActivitySection";
import type { ApiKey, SenderIdentity, Subscription, Team, Tenant, WebhookActivity } from "./actions";

type ContactsPage = { total: number };
type CampaignAnalytics = { sends: { total: number } };

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

  // contactsResult/analyticsResult only ever feed BillingSection's two
  // "total" figures below — no point fetching either for a member, whose
  // own /contacts and /analytics/campaign calls would additionally 403 if
  // they don't happen to hold those specific permissions too
  // (RequirePermission), unrelated to billing being owner-only.
  const [subscriptionResult, teamResult, gbpResult, sendersResult, contactsResult, analyticsResult, apiKeysResult, webhookActivityResult] =
    await Promise.all([
      isOwner ? apiFetch<Subscription>("/subscription") : Promise.resolve(null),
      isOwner ? apiFetch<Team>("/team") : Promise.resolve(null),
      apiFetch<GbpStatus>("/gbp/status"),
      apiFetch<SenderIdentity[]>("/sender-identities"),
      isOwner ? apiFetch<ContactsPage>("/contacts") : Promise.resolve(null),
      isOwner ? apiFetch<CampaignAnalytics>("/analytics/campaign") : Promise.resolve(null),
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
              isOwner && subscriptionResult
                ? subscriptionResult.ok
                  ? (
                    <BillingSection
                      subscription={subscriptionResult.data}
                      contactsTotal={contactsResult?.ok ? contactsResult.data.total : null}
                      requestsSentTotal={analyticsResult?.ok ? analyticsResult.data.sends.total : null}
                    />
                  )
                  : <SectionError message={subscriptionResult.message} status={subscriptionResult.status} />
                : undefined
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

function SectionError({ message, status }: { message: string; status: number }) {
  const text = status === 401 ? "Your session has expired. Log in again to continue." : message;

  return (
    <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
      {text}
    </p>
  );
}
