"use server";

import { redirect } from "next/navigation";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

export type GbpStatus = {
  status: "connected" | "revoked" | "not_connected";
  location_id: string | null;
  review_link: string | null;
  last_synced_at: string | null;
};

/**
 * Bound directly to a <form action={...}> — no client JS needed. Always
 * ends in a redirect: to Google's real OAuth consent screen on success, or
 * back to this same page with an error code on failure (matching the
 * pattern GbpController's own callback already uses for its own error
 * cases, so both paths land the tenant on the same screen).
 */
export async function startGbpConnect(): Promise<void> {
  await requireToken();

  const result = await apiFetch<{ redirect_url: string }>("/gbp/connect");

  if (!result.ok) {
    redirect("/gbp/connect?error=connect_failed");
  }

  redirect(result.data.redirect_url);
}
