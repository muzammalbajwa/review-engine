"use server";

import { apiFetch } from "@/lib/api";

export type QuickAddFormInfo = { business_name: string };

/**
 * No requireToken() here — this whole route is the one place in the app
 * that's deliberately reachable with no session at all
 * (.claude/CLAUDE.md quick-add: "opens the same form without requiring
 * login"). skipAuth on both calls means apiFetch never even looks for a
 * token cookie.
 */
export async function getQuickAddFormInfo(
  token: string,
): Promise<{ ok: true; data: QuickAddFormInfo } | { ok: false; status: number; message: string }> {
  const result = await apiFetch<QuickAddFormInfo>(`/quick/${token}`, { skipAuth: true });

  return result.ok ? { ok: true, data: result.data } : { ok: false, status: result.status, message: result.message };
}

export type GuestQuickAddState =
  | { status: "success" }
  | { status: "error"; message: string; fields: Record<string, string[]> | null };

export async function submitGuestQuickAdd(
  token: string,
  name: string,
  phone: string,
  email: string,
): Promise<GuestQuickAddState> {
  const result = await apiFetch(`/quick/${token}`, {
    method: "POST",
    skipAuth: true,
    body: { name, phone: phone || undefined, email: email || undefined },
  });

  if (!result.ok) {
    return { status: "error", message: result.message, fields: result.fields };
  }

  return { status: "success" };
}
