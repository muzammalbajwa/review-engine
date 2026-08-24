"use server";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

export type QuickAddContactState =
  | { status: "success" }
  | { status: "error"; message: string; fields: Record<string, string[]> | null };

export async function quickAddContact(name: string, phone: string, email: string): Promise<QuickAddContactState> {
  await requireToken();

  const result = await apiFetch("/contacts/quick-add", {
    method: "POST",
    body: { name, phone: phone || undefined, email: email || undefined },
  });

  if (!result.ok) {
    return { status: "error", message: result.message, fields: result.fields };
  }

  return { status: "success" };
}
