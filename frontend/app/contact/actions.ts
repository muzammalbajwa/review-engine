"use server";

import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/session";

export type ContactPrefill = { name: string; email: string } | null;

/**
 * Pre-fill for a logged-in tenant visiting the public /contact page
 * (nice-to-have, not required — a prospect with no account gets an empty
 * form same as before). Reuses GET /tenant's `user` field rather than a
 * dedicated endpoint. A missing/expired token, or any other failure, just
 * means no pre-fill — this page never requires login, so it never
 * redirects or errors here.
 */
export async function getContactPrefill(): Promise<ContactPrefill> {
  const token = await getToken();
  if (token === null) {
    return null;
  }

  const result = await apiFetch<{ user: { name: string; email: string } }>("/tenant");

  return result.ok ? { name: result.data.user.name, email: result.data.user.email } : null;
}

export type ContactFormState =
  | { status: "success"; message: string }
  | { status: "error"; message: string; fields: Record<string, string[]> | null };

/**
 * `company` is the honeypot (ContactForm.tsx renders it off-screen, never
 * shown to a real visitor) — passed straight through, unvalidated
 * client-side, exactly as the visitor's browser submitted it. The backend
 * (SubmitContactMessageRequest/ContactMessageController) decides silently
 * what to do with it; this layer never inspects it.
 */
export async function submitContactMessage(
  name: string,
  email: string,
  message: string,
  company: string,
): Promise<ContactFormState> {
  const result = await apiFetch<{ message: string }>("/contact", {
    method: "POST",
    skipAuth: true,
    body: { name, email, message, company: company || undefined },
  });

  if (!result.ok) {
    return { status: "error", message: result.message, fields: result.fields };
  }

  return { status: "success", message: result.data.message };
}
