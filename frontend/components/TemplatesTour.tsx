"use client";

import type { Step } from "react-joyride";

import { ScreenTour } from "./ScreenTour";

/**
 * Targets TemplateEditor.tsx's own [data-tour="template-steps"] (the 3
 * step tabs), [data-tour="compliance-check"] (the SinglePathLine +
 * pass/block result), and [data-tour="template-message"] (the textarea
 * area — named to avoid colliding with the textarea's own
 * id="template-body").
 *
 * The three real step names — Initial request, Follow-up, Reactivation —
 * are TemplateEditor.tsx's own STEP_LABELS (and Analytics's identical
 * STEP_LABELS for "Sends by step"), not "check-in, review request,
 * follow-up." Verified against the actual code before writing this,
 * not assumed: .claude/QUEUE.md's own "three mechanics" section
 * confirms the real shape — step 2 only sends if the contact hasn't
 * clicked/reviewed yet, step 3 is the slow, business-hours-paced
 * reactivation drip, not a second follow-up.
 *
 * checkResults is pre-populated from initialTemplates on mount
 * (COMPLIANCE.md: "Ship compliant defaults" — every tenant already has
 * pass-status default templates before ever touching this screen), so
 * the compliance result area is a safe, always-present target on first
 * load — no need to wait for a live edit.
 */
const STEPS: Step[] = [
  {
    target: '[data-tour="template-steps"]',
    placement: "bottom",
    title: "Three messages, one sequence",
    content:
      "Initial request goes out first. Follow-up sends only if they haven't clicked or reviewed yet. Reactivation trickles out slowly after that.",
  },
  {
    target: '[data-tour="compliance-check"]',
    placement: "top",
    title: "Checked before it can go live",
    content:
      "Every message is checked automatically for gating language, staff-name requests, and review incentives — exactly what gets a profile suspended. A blocked message can't be saved.",
  },
  {
    target: '[data-tour="template-message"]',
    placement: "top",
    title: "Good defaults, ready to go",
    content: "Edit if you want your own voice — most businesses keep these exactly as they are.",
  },
];

export function TemplatesTour({ alreadySeen }: { alreadySeen: boolean }) {
  return <ScreenTour tourKey="templates_editor" steps={STEPS} alreadySeen={alreadySeen} />;
}
