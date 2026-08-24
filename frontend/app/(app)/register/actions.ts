"use server";

import { redirect } from "next/navigation";

import { apiFetch } from "@/lib/api";
import { setSession } from "@/lib/session";

export type RegisterState = {
  error: string | null;
  fields: Record<string, string[]> | null;
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

  if (!name || !businessName || !email || !password) {
    return { error: "Fill in every field to create your account.", fields: null };
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
    return { error: result.message, fields: result.fields };
  }

  await setSession(result.data.token, result.data.user.is_admin);

  redirect("/onboarding");
}
