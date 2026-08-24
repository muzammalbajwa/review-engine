"use server";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

export type Review = {
  id: number;
  google_review_id: string;
  rating: number | null;
  text: string | null;
  reviewer_name: string | null;
  review_created_at: string | null;
  needs_reply: boolean;
  synced_at: string;
};

export type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

export type Reply = {
  id: number;
  review_id: number;
  body: string;
  posted_at: string | null;
  policy_violation: boolean;
  policy_violation_reason: string | null;
};

export type ReplyActionState =
  | { status: "posted"; reply: Reply }
  | { status: "policy_violation"; message: string; reply: Reply }
  | { status: "revoked"; message: string }
  | { status: "error"; message: string };

type PolicyViolationData = { reply: Reply };

/**
 * .claude/API.md: POST /reviews/{id}/reply. .claude/COMPLIANCE.md: "Surface
 * Google's PolicyViolation status on rejected auto-replies... No
 * competitor shows this" — the exact reason and drafted body come back
 * unmodified, never paraphrased into a generic failure.
 */
export async function postReviewReply(reviewId: number): Promise<ReplyActionState> {
  await requireToken();

  const result = await apiFetch<Reply, PolicyViolationData>(`/reviews/${reviewId}/reply`, {
    method: "POST",
  });

  if (result.ok) {
    return { status: "posted", reply: result.data };
  }

  if (result.error === "policy_violation" && result.data) {
    return { status: "policy_violation", message: result.message, reply: result.data.reply };
  }

  if (result.error === "gbp_connection_revoked") {
    return { status: "revoked", message: result.message };
  }

  return { status: "error", message: result.message };
}
