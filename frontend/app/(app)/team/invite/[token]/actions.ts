"use server";

import { redirect } from "next/navigation";

import { apiFetch } from "@/lib/api";
import { setSession } from "@/lib/session";

export type AcceptInviteState = {
  error: string | null;
  fields: Record<string, string[]> | null;
};

type AcceptInviteResponse = {
  token: string;
  user: { id: number; name: string; email: string; role: string; is_admin: boolean };
  tenant: { id: string; name: string };
};

/**
 * Mirrors app/(app)/register/actions.ts's register() shape exactly — same
 * response envelope (token/user/tenant), same setSession() + redirect —
 * the one difference is the token param baked into the URL (via bind
 * below) instead of email/business_name form fields, since both come from
 * the invite itself server-side, never from this form.
 */
export async function acceptInvite(
  token: string,
  _prevState: AcceptInviteState,
  formData: FormData,
): Promise<AcceptInviteState> {
  const name = String(formData.get("name") ?? "");
  const password = String(formData.get("password") ?? "");
  const passwordConfirmation = String(formData.get("password_confirmation") ?? "");

  if (!name || !password) {
    return { error: "Fill in every field to join the team.", fields: null };
  }

  const result = await apiFetch<AcceptInviteResponse>(`/team/invite/${token}/accept`, {
    method: "POST",
    body: { name, password, password_confirmation: passwordConfirmation },
    skipAuth: true,
  });

  if (!result.ok) {
    return { error: result.message, fields: result.fields };
  }

  await setSession(result.data.token, result.data.user.is_admin);

  redirect("/dashboard");
}
