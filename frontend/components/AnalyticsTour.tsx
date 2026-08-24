"use client";

import type { Step } from "react-joyride";

import { ScreenTour } from "./ScreenTour";

/**
 * Targets app/(app)/analytics/page.tsx's own
 * [data-tour="analytics-heading"], [data-tour="analytics-stats"] (the 3
 * StatTiles), and [data-tour="analytics-by-step"] (the Sends-by-step
 * bars). The page renders a plain "No campaign activity yet" line
 * instead of any of that when sends.total === 0 — a brand new tenant's
 * first visit here, so `hasActivity` picks between a single prospective
 * step (nothing real to point at yet) and the real three-step
 * walkthrough, rather than pointing at elements that don't exist.
 *
 * "Initial request / Follow-up / Reactivation" are this page's own
 * STEP_LABELS — identical wording to TemplatesTour's, since it's
 * literally the same three real steps described from a different
 * screen, not a second invented name for the same thing.
 */
function buildSteps(hasActivity: boolean): Step[] {
  if (!hasActivity) {
    return [
      {
        target: '[data-tour="analytics-heading"]',
        placement: "bottom",
        title: "Your campaign, measured",
        content:
          "Once review requests start going out, you'll see requests sent, click rate, and reviews landed here — plus a breakdown by step (initial request, follow-up, reactivation).",
      },
    ];
  }

  return [
    {
      target: '[data-tour="analytics-heading"]',
      placement: "bottom",
      title: "Your campaign, measured",
      content: "Track how your review requests are performing, updating as they go out.",
    },
    {
      target: '[data-tour="analytics-stats"]',
      placement: "bottom",
      title: "The headline numbers",
      content:
        "Requests sent, plus click rate and reviews landed as a share of those requests — reviews landed is the one that matters most.",
    },
    {
      target: '[data-tour="analytics-by-step"]',
      placement: "top",
      title: "Broken down by step",
      content: "See how initial request, follow-up, and reactivation are each performing on their own.",
    },
  ];
}

export function AnalyticsTour({ alreadySeen, hasActivity }: { alreadySeen: boolean; hasActivity: boolean }) {
  return <ScreenTour tourKey="analytics" steps={buildSteps(hasActivity)} alreadySeen={alreadySeen} />;
}
