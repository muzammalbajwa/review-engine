"use client";

import type { Step } from "react-joyride";

import { ScreenTour } from "./ScreenTour";

/**
 * Targets app/(app)/reviews/page.tsx's own [data-tour="reviews-heading"]
 * and [data-tour="reviews-area"] — the latter wraps the whole
 * conditional result block (error / "No reviews yet." / <ReviewsList>)
 * so it's a stable target regardless of which branch renders. A brand
 * new tenant's very first visit here is overwhelmingly likely to be the
 * empty branch (reviews only exist once GBP has synced some over), so
 * this can't assume a real review row exists to point at.
 *
 * "Draft & post reply" (ReviewsList.tsx's own button label) really is
 * one action, not draft-then-approve — verified against
 * ReviewController::reply() (backend): ClaudeReplyDrafter::draft() and
 * GoogleReviewReplyClient::postReply() both happen inside the same
 * request, no intermediate "review the draft" state exists anywhere in
 * the flow. Said plainly here rather than assumed.
 */
function buildSteps(gbpConnected: boolean): Step[] {
  const steps: Step[] = [
    {
      target: '[data-tour="reviews-heading"]',
      placement: "bottom",
      title: "Where your reviews land",
      content: "Every review Google syncs over shows up here — good, bad, or no rating at all.",
    },
    {
      target: '[data-tour="reviews-area"]',
      placement: "top",
      title: "One click drafts and posts",
      content:
        "A review that needs a reply is flagged. “Draft & post reply” drafts one and posts it straight to Google — there's no separate approval step.",
    },
  ];

  if (!gbpConnected) {
    steps.push({
      target: '[data-tour="reviews-area"]',
      placement: "top",
      title: "One thing first",
      content: "Replying needs your Google Business Profile connected — head to Settings to connect it.",
    });
  }

  return steps;
}

export function ReviewsTour({ alreadySeen, gbpConnected }: { alreadySeen: boolean; gbpConnected: boolean }) {
  return <ScreenTour tourKey="reviews_list" steps={buildSteps(gbpConnected)} alreadySeen={alreadySeen} />;
}
