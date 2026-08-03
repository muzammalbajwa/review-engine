"use server";

import { redirect } from "next/navigation";

import { apiFetch } from "@/lib/api";
import { setSession } from "@/lib/session";

export type LoginState = {
  error: string | null;
};

type LoginResponse = {
  token: string;
  user: { id: number; name: string; email: string; role: string; is_admin: boolean };
};

export async function login(_prevState: LoginState, formData: FormData): Promise<LoginState> {
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");

  if (email === "" || password === "") {
    return { error: "Email and password are required." };
  }

  const result = await apiFetch<LoginResponse>("/login", {
    method: "POST",
    body: { email, password },
    skipAuth: true,
  });

  if (!result.ok) {
    // .claude/API.md: login intentionally returns the same message either
    // way — don't editorialize this into something more specific here.
    return { error: result.message };
  }

  await setSession(result.data.token, result.data.user.is_admin);

  redirect("/contacts/import");
}
