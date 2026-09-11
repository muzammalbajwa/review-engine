"use server";

import { redirect } from "next/navigation";

import { apiFetch } from "@/lib/api";
import { setSession } from "@/lib/session";

export type RegisterState = {
  error: string | null;
  fields: Record<string, string[]> | null;
  // QA-audit fix (Finding 3): echoed back so RegisterForm.tsx can
  // re-populate name/business_name/email via defaultValue after any
  // validation error — never includes password/password_confirmation,
  // which must always come back empty regardless of what failed.
  values: { name: string; business_name: string; email: string } | null;
};

type RegisterResponse = {
  token: string;
  user: { id: number; name: string; email: string; role: string; is_admin: boolean };
  tenant: { id: string; name: string };
};

export async function register(_prevState: RegisterState, formData: FormData): Promise<RegisterState> {
  const name = String(formData.get("name") ?? "");
  const businessName = String(formData.get("business_name") ?? "");
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");
  const passwordConfirmation = String(formData.get("password_confirmation") ?? "");

  const values = { name, business_name: businessName, email };

  if (!name || !businessName || !email || !password) {
    return { error: "Fill in every field to create your account.", fields: null, values };
  }

  const result = await apiFetch<RegisterResponse>("/register", {
    method: "POST",
    body: {
      name,
      business_name: businessName,
      email,
      password,
      password_confirmation: passwordConfirmation,
    },
    skipAuth: true,
  });

  if (!result.ok) {
    return { error: result.message, fields: result.fields, values };
  }

  await setSession(result.data.token, result.data.user.is_admin);

  redirect("/onboarding");
}
