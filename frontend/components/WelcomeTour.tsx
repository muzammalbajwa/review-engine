"use client";

import type { Step } from "react-joyride";

import { Tour } from "./Tour";

/**
 * The first-login welcome tour. Content only — chrome (styling,
 * keyboard/focus, the finish/skip callback) lives in the shared <Tour />
 * primitive. .claude/DESIGN.md Voice: plain verbs, second person, no
 * SaaS jargon — "Here's where your customers show up," not "This
 * section displays contact records." Short enough to read in a few
 * seconds per step; this is a tour, not documentation.
 *
 * Steps 3-4 are built from REAL account state (hasContacts,
 * gbpConnected), not a fixed list — they have to be, since the targets
 * themselves only exist in the DOM under matching conditions:
 * [data-tour="kpi-strip"] only renders when the dashboard has contacts
 * (app/(app)/dashboard/page.tsx's isBrandNew branch renders
 * [data-tour="get-started"] instead, no KPI grid at all), and
 * [data-tour="setup-gbp"] only renders inside IncompleteSetupBanner when
 * GBP isn't connected. A step whose target never exists would just sit
 * there failing silently until targetWaitTimeout — building the list
 * from the same state the page itself branches on avoids that entirely.
 */
function buildSteps(hasContacts: boolean, gbpConnected: boolean): Step[] {
  const steps: Step[] = [
    {
      target: "body",
      placement: "center",
      title: "Welcome to ReviewEngine",
      content:
        "Here's a quick look at where everything lives. Once you're set up, review requests go out on their own — no more chasing customers for reviews by hand.",
    },
    {
      target: '[data-tour="sidebar-nav"]',
      placement: "right",
      title: "Everything lives in the sidebar",
      content: (
        <ul className="flex flex-col gap-1 text-left">
          <li>
            <strong className="font-semibold">Contacts</strong> — your customers go in here
          </li>
          <li>
            <strong className="font-semibold">Templates</strong> — what they&apos;ll receive
          </li>
          <li>
            <strong className="font-semibold">Reviews</strong> — what comes back
          </li>
          <li>
            <strong className="font-semibold">Analytics</strong> — how it&apos;s trending
          </li>
          <li>
            <strong className="font-semibold">Settings</strong> — profile, billing, connections
          </li>
        </ul>
      ),
    },
  ];

  if (hasContacts) {
    steps.push({
      target: '[data-tour="kpi-strip"]',
      placement: "bottom",
      title: "Your results, at a glance",
      content:
        "Requests sent, click rate, reviews landed, your average rating — real numbers, updating as customers respond.",
    });

    if (!gbpConnected) {
      steps.push({
        target: '[data-tour="setup-gbp"]',
        placement: "bottom",
        title: "One thing left",
        content: "Connect your Google Business Profile and reviews start syncing in on their own.",
      });
    }
  } else {
    steps.push({
      target: '[data-tour="get-started"]',
      placement: "top",
      title: "One thing left",
      content:
        "Add your first customers here and review requests start going out on their own. This is also where you'll see requests sent, click rate, and reviews landing, once they do.",
    });
  }

  steps.push({
    target: "body",
    placement: "center",
    title: "You're set",
    content: "That's the tour. Explore, or come back to it any time with “Take the tour again” in the sidebar.",
  });

  return steps;
}

export function WelcomeTour({
  run,
  onFinish,
  hasContacts,
  gbpConnected,
}: {
  run: boolean;
  onFinish: () => void;
  hasContacts: boolean;
  gbpConnected: boolean;
}) {
  return <Tour steps={buildSteps(hasContacts, gbpConnected)} run={run} onFinish={onFinish} />;
}
