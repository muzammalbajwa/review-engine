"use server";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

export type TourStatus = {
  has_completed_welcome_tour: boolean;
  tours_seen: Record<string, boolean>;
};

/**
 * Product-tour progress — server-side, per-user (see the
 * has_completed_welcome_tour/tours_seen migration's own docblock).
 * AppShell reads GET /tours/status directly (it's already an async
 * Server Component doing the same thing for /tenant); these two actions
 * are the write side, called from SidebarShell — a Client Component —
 * when a tour finishes or is explicitly skipped.
 */
export async function getTourStatus(): Promise<TourStatus | null> {
  await requireToken();

  const result = await apiFetch<TourStatus>("/tours/status");

  return result.ok ? result.data : null;
}

export async function markWelcomeTourSeen(): Promise<void> {
  await requireToken();
  await apiFetch("/tours/welcome/complete", { method: "POST" });
}

export async function markScreenTourSeen(key: string): Promise<void> {
  await requireToken();
  await apiFetch(`/tours/screens/${key}/complete`, { method: "POST" });
}
