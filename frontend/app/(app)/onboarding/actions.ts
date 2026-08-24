"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

export type OnboardingStatus = {
  subscribed: boolean;
  tenant_status: "pending" | "trialing" | "active" | "trial_expired" | "canceled";
  plan: string | null;
  trial_ends_at: string | null;
  gbp_status: "connected" | "revoked" | "not_connected";
  gbp_step_done: boolean;
  contacts_count: number;
  contacts_step_done: boolean;
  completed: boolean;
};

export async function getOnboardingStatus(): Promise<OnboardingStatus | null> {
  await requireToken();

  const result = await apiFetch<OnboardingStatus>("/onboarding/status");

  return result.ok ? result.data : null;
}

/**
 * Same action for "I connected it" and "skip for now" — both mean "don't
 * ask again this run." The dashboard's incomplete-setup banner reads the
 * live gbp_status separately, so skipping here never hides that it's
 * still not actually connected.
 */
export async function markGbpStepDone(): Promise<void> {
  await requireToken();
  await apiFetch("/onboarding/gbp-step-done", { method: "POST" });
  revalidatePath("/onboarding");
}

export async function markContactsStepDone(): Promise<void> {
  await requireToken();
  await apiFetch("/onboarding/contacts-step-done", { method: "POST" });
  revalidatePath("/onboarding");
}

export async function completeOnboarding(): Promise<void> {
  await requireToken();
  await apiFetch("/onboarding/complete", { method: "POST" });
  redirect("/dashboard");
}

export type StartTrialState = { status: "success" } | { status: "error"; message: string };

/**
 * The 7-day free trial's start (.claude/BILLING.md: no card, no Lemon
 * Squeezy call — see backend/app/Models/Tenant.php's startTrial()). This is the only
 * action onboarding's plan step performs now; the paid, card-required
 * path lives in Settings/Billing's conversion flow instead
 * (settings/actions.ts's subscribe), reached only after a tenant
 * is already trialing or trial_expired.
 */
export async function startTrial(plan: string): Promise<StartTrialState> {
  await requireToken();

  const result = await apiFetch("/onboarding/start-trial", {
    method: "POST",
    body: { plan },
  });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  revalidatePath("/onboarding");

  return { status: "success" };
}
