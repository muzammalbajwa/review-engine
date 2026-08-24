"use server";

import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";

import { apiFetch } from "@/lib/api";
import { clearToken, requireToken } from "@/lib/session";

// Subscription type + getSubscription/toggleAutoRenew/subscribe actions
// (Lemon Squeezy checkout + auto-renew toggle) removed with the backend's
// /subscribe, /subscription, and /subscription/portal routes
// (lemonsqueezy/laravel package removal). Recoverable from git history —
// pending a Paddle-backed rebuild of SubscriptionController and these
// actions together.

export async function logout(): Promise<void> {
  await requireToken();
  await apiFetch("/logout", { method: "POST" });
  await clearToken();
  redirect("/login");
}

export type Permissions = {
  contacts: boolean;
  templates: boolean;
  reviews: boolean;
  analytics: boolean;
};

export type Tenant = {
  id: string;
  name: string;
  type: "admin" | "customer";
  quick_add_token: string;
  created_at: string;
  plan: string | null;
  // "past_due": a failed Paddle payment during its dunning retry window
  // — does NOT block sending access (Tenant::sendingBlockedReason() has
  // no branch for it, deliberately — .claude/BILLING.md's dunning
  // design). Only "canceled" (Paddle's dunning genuinely exhausted) does.
  status: "pending" | "trialing" | "active" | "trial_expired" | "past_due" | "canceled";
  trial_ends_at: string | null;
  // Non-null only while status is "active" and current_period_end is
  // within 10 days — the dashboard-banner counterpart to
  // billing:send-renewal-reminders' emails (.claude/BILLING.md "Renewal
  // reminders").
  renewal_reminder: {
    days_until: number;
    auto_renew: boolean;
    period_end: string;
    billing_interval: "monthly" | "annual" | null;
    amount_display: string | null;
  } | null;
  // The "add email verification" decision doc. null unless sending is
  // actually blocked right now (Tenant::sendingBlockedReason() — the
  // exact same source of truth the backend gate enforces): 'trial_expired',
  // 'subscription_ended', or 'email_unverified'.
  sending_blocked_reason: "trial_expired" | "subscription_ended" | "email_unverified" | null;
  // THIS user's own verification state — not necessarily the tenant
  // owner's, if a member is asking. Only meaningful for whether sending
  // is unblocked when is_owner is also true.
  email_verified: boolean;
  // The CALLING user's own role/permissions, not the tenant's — read
  // fresh on every /tenant fetch (AppShell, every page navigation), so a
  // permission the owner just revoked reflects here immediately rather
  // than only after the member's next login.
  role: "owner" | "member";
  is_owner: boolean;
  permissions: Permissions;
};

export type TenantUpdateState = { status: "success"; tenant: Tenant } | { status: "error"; message: string };

/**
 * .claude/API.md: "Tenant: GET/PATCH /tenant (self)" — the business
 * profile section's only field right now is the business name (that's the
 * entire editable tenant schema; no address/phone/logo exists yet).
 */
export async function updateTenant(name: string): Promise<TenantUpdateState> {
  await requireToken();

  const result = await apiFetch<Tenant>("/tenant", { method: "PATCH", body: { name } });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  revalidatePath("/settings");

  return { status: "success", tenant: result.data };
}

export type ResendVerificationState =
  | { status: "success"; message: string }
  | { status: "error"; message: string };

/**
 * The "add email verification" decision doc's "Resend verification
 * email" action — POST /email/verification-notification, authenticated,
 * throttled server-side. Safe to click repeatedly: the backend itself
 * returns a harmless "already verified" message rather than a second
 * email once verification has already happened.
 */
export async function resendVerificationEmail(): Promise<ResendVerificationState> {
  await requireToken();

  const result = await apiFetch<{ message: string }>("/email/verification-notification", { method: "POST" });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  return { status: "success", message: result.data.message };
}

export type SenderIdentity = {
  id: number;
  from_name: string;
  from_email: string;
  verified: boolean;
};

export type SenderIdentityCreateState =
  | { status: "success"; identity: SenderIdentity }
  | { status: "error"; message: string; fields: Record<string, string[]> | null };

export async function createSenderIdentity(fromName: string, fromEmail: string): Promise<SenderIdentityCreateState> {
  await requireToken();

  const result = await apiFetch<SenderIdentity>("/sender-identities", {
    method: "POST",
    body: { from_name: fromName, from_email: fromEmail },
  });

  if (!result.ok) {
    return { status: "error", message: result.message, fields: result.fields };
  }

  revalidatePath("/settings");

  return { status: "success", identity: result.data };
}

export type ApiKey = {
  id: number;
  created_at: string;
  last_used_at: string | null;
  expires_at: string | null;
  status: "active" | "expiring";
};

export async function getApiKeys(): Promise<{ ok: true; data: ApiKey[] } | { ok: false; message: string }> {
  await requireToken();

  const result = await apiFetch<ApiKey[]>("/api-keys");

  return result.ok ? { ok: true, data: result.data } : { ok: false, message: result.message };
}

export type IssueApiKeyState =
  | { status: "success"; token: string; key: ApiKey; gracedKeys: ApiKey[] }
  | { status: "error"; message: string };

/**
 * .claude/API.md webhook API spec: issuing a new key gives any existing
 * one a 24h grace period instead of deleting it — see ApiKeyController.
 * The plaintext token is returned exactly once here; it's never
 * retrievable again after this response (Sanctum hashes it at rest).
 *
 * `gracedKeys` carries the *real* expires_at ApiKeyController just wrote
 * for any key(s) this rotation just grace-perioded — the only way the UI
 * can show "works until [date]" immediately, instead of a generic "24
 * hours" or nothing at all until the next full key list refetch.
 */
export async function issueApiKey(): Promise<IssueApiKeyState> {
  await requireToken();

  const result = await apiFetch<{ token: string; key: ApiKey; graced_keys: ApiKey[] }>("/api-keys", {
    method: "POST",
  });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  revalidatePath("/settings");

  return {
    status: "success",
    token: result.data.token,
    key: result.data.key,
    gracedKeys: result.data.graced_keys,
  };
}

export type WebhookActivityItem = {
  id: number;
  name: string;
  external_id: string | null;
  created_at: string;
};

export type WebhookActivity = {
  count: number;
  window_days: number;
  recent: WebhookActivityItem[];
};

export async function getWebhookActivity(): Promise<
  { ok: true; data: WebhookActivity } | { ok: false; message: string }
> {
  await requireToken();

  const result = await apiFetch<WebhookActivity>("/contacts/webhook-activity");

  return result.ok ? { ok: true, data: result.data } : { ok: false, message: result.message };
}

export type SendTestEventState =
  | { status: "success"; contactId: number }
  | { status: "error"; message: string };

/**
 * Calls the real POST /api/v1/contacts — the exact endpoint a tenant's
 * Zapier/Make/CRM would call — using the dashboard session instead of a
 * pasted API key (routes/api.php: the ability check already lets a
 * session's wildcard-scoped token through). Proves the endpoint and the
 * enrollment pipeline work end-to-end without requiring an API key to
 * exist first. Tagged with the settings-test- external_id prefix so
 * GET /contacts/webhook-activity (below) doesn't count it as real
 * integration traffic.
 */
export async function sendTestEvent(): Promise<SendTestEventState> {
  await requireToken();

  const result = await apiFetch<{ id: number }>("/contacts", {
    method: "POST",
    body: {
      name: "Test Contact",
      phone: "+15555550199",
      external_id: `settings-test-${Date.now()}`,
    },
  });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  return { status: "success", contactId: result.data.id };
}

// openBillingPortal (Lemon Squeezy's hosted billing portal link) removed
// with the backend's /subscription/portal route — see the note above
// getSubscription's old location.

export type TeamMember = {
  id: number;
  name: string;
  email: string;
  role: "owner" | "member";
  permissions: Permissions;
};

export type PendingInvite = {
  id: number;
  email: string;
  invited_at: string;
  expires_at: string;
};

export type Team = { members: TeamMember[]; pending_invites: PendingInvite[] };

/**
 * Owner-only server-side (EnsureTenantOwner) — this action itself has no
 * separate gate beyond that; a member's request would just get the same
 * 403 the API returns, same as every other owner-only action here.
 */
export async function getTeam(): Promise<{ ok: true; data: Team } | { ok: false; message: string }> {
  await requireToken();

  const result = await apiFetch<Team>("/team");

  return result.ok ? { ok: true, data: result.data } : { ok: false, message: result.message };
}

export type InviteTeamMemberState =
  | { status: "success"; invite: PendingInvite }
  | { status: "error"; message: string; fields: Record<string, string[]> | null };

export async function inviteTeamMember(email: string): Promise<InviteTeamMemberState> {
  await requireToken();

  const result = await apiFetch<PendingInvite>("/team/invite", { method: "POST", body: { email } });

  if (!result.ok) {
    return { status: "error", message: result.message, fields: result.fields };
  }

  revalidatePath("/settings");

  return { status: "success", invite: result.data };
}

export type RevokeInviteState = { status: "error"; message: string } | { status: "success" };

export async function revokeInvite(inviteId: number): Promise<RevokeInviteState> {
  await requireToken();

  const result = await apiFetch(`/team/invites/${inviteId}`, { method: "DELETE" });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  revalidatePath("/settings");

  return { status: "success" };
}

export type UpdatePermissionsState =
  | { status: "success"; member: TeamMember }
  | { status: "error"; message: string };

/**
 * Sends only the changed key(s), not the whole permissions object —
 * matches UpdateMemberPermissionsRequest's own merge-not-replace shape
 * server-side (TeamController::updatePermissions), so toggling one
 * checkbox can never accidentally clobber another permission this
 * request didn't touch.
 */
export async function updateMemberPermissions(
  memberId: number,
  permissions: Partial<Permissions>,
): Promise<UpdatePermissionsState> {
  await requireToken();

  const result = await apiFetch<TeamMember>(`/team/members/${memberId}/permissions`, {
    method: "PATCH",
    body: { permissions },
  });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  revalidatePath("/settings");

  return { status: "success", member: result.data };
}
