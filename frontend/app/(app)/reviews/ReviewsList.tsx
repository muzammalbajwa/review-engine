"use client";

import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { postReviewReply, type ReplyActionState, type Review } from "./actions";

type RowState = ReplyActionState | { status: "pending" } | { status: "idle" };

export function ReviewsList({ initialReviews }: { initialReviews: Review[] }) {
  const [reviews, setReviews] = useState(initialReviews);
  const [rowStates, setRowStates] = useState<Record<number, RowState>>({});

  async function handleReply(reviewId: number) {
    setRowStates((prev) => ({ ...prev, [reviewId]: { status: "pending" } }));

    const state = await postReviewReply(reviewId);

    setRowStates((prev) => ({ ...prev, [reviewId]: state }));

    if (state.status === "posted") {
      setReviews((prev) => prev.map((r) => (r.id === reviewId ? { ...r, needs_reply: false } : r)));
    }
  }

  return (
    <ul className="flex flex-col gap-4">
      {reviews.map((review) => (
        <ReviewRow
          key={review.id}
          review={review}
          state={rowStates[review.id] ?? { status: "idle" }}
          onReply={() => handleReply(review.id)}
        />
      ))}
    </ul>
  );
}

function ReviewRow({
  review,
  state,
  onReply,
}: {
  review: Review;
  state: RowState;
  onReply: () => void;
}) {
  return (
    <li className="rounded-lg border border-border p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-sm font-medium text-foreground">{review.reviewer_name ?? "Anonymous"}</p>
          <p className="text-xs text-muted-foreground">
            {review.rating !== null ? `${review.rating}/5` : "No rating given"}
            {review.review_created_at &&
              ` · ${new Date(review.review_created_at).toLocaleDateString()}`}
          </p>
        </div>
        {review.needs_reply ? (
          // Solid fill, not the bg-warning/10 tint used elsewhere: gold
          // text on a near-white tint is 2.4:1, well under WCAG AA's
          // 4.5:1 for text. text-warning-foreground on solid bg-warning
          // is 5.8:1 — the same pattern Button's default variant already
          // uses for bg-primary/text-primary-foreground.
          <span className="rounded-full bg-warning px-2 py-0.5 text-xs font-medium text-warning-foreground">
            Needs reply
          </span>
        ) : (
          <span className="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
            Replied
          </span>
        )}
      </div>

      {review.text && <p className="mt-3 text-sm text-foreground">{review.text}</p>}

      {review.needs_reply && (
        <div className="mt-4">
          {(state.status === "idle" || state.status === "error") && (
            <>
              <Button size="sm" onClick={onReply}>
                Draft &amp; post reply
              </Button>
              {state.status === "error" && (
                <p role="alert" className="mt-2 text-sm text-destructive">
                  {state.message}
                </p>
              )}
            </>
          )}

          {state.status === "pending" && (
            <p className="text-sm text-muted-foreground">Drafting and posting reply…</p>
          )}

          {state.status === "posted" && (
            <div className="rounded-lg border border-border bg-muted/40 p-3 text-sm">
              <p className="font-medium text-foreground">Reply posted</p>
              <p className="mt-1 text-foreground">{state.reply.body}</p>
            </div>
          )}

          {state.status === "policy_violation" && (
            <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm">
              <p className="font-medium text-destructive">Google rejected this reply: {state.message}</p>
              <p className="mt-2 text-muted-foreground">Drafted text:</p>
              <p className="mt-1 text-foreground">{state.reply.body}</p>
              <div className="mt-3">
                <Button size="sm" variant="outline" onClick={onReply}>
                  Try again
                </Button>
              </div>
            </div>
          )}

          {state.status === "revoked" && (
            <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
              <p>{state.message}</p>
              <Link
                href="/gbp/connect"
                className="mt-1 inline-block font-medium underline-offset-4 hover:underline"
              >
                Reconnect Google Business Profile
              </Link>
            </div>
          )}
        </div>
      )}
    </li>
  );
}
