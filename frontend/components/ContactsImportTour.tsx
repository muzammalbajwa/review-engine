"use client";

import type { Step } from "react-joyride";

import { ScreenTour } from "./ScreenTour";

/**
 * Targets ImportWizard.tsx's own [data-tour="csv-upload"]/
 * [data-tour="import-steps"] and app/(app)/contacts/import/page.tsx's
 * [data-tour="quick-add-link"]. Unconditional three steps — unlike
 * Reviews/Analytics, this screen renders the same upload UI regardless
 * of whether the tenant has any contacts yet, so there's no empty-state
 * branch to design around.
 */
const STEPS: Step[] = [
  {
    target: '[data-tour="csv-upload"]',
    placement: "bottom",
    title: "Bring in your past customers",
    content:
      "Upload a CSV and every row starts the same review-request sequence automatically — no manual sending.",
  },
  {
    target: '[data-tour="import-steps"]',
    placement: "bottom",
    title: "Three quick steps",
    content: "Upload your file, fix anything flagged, then confirm. Takes about a minute.",
  },
  {
    target: '[data-tour="quick-add-link"]',
    placement: "bottom",
    title: "Just finished a job?",
    content: "Skip the spreadsheet — add one customer at a time right after you wrap up.",
  },
];

export function ContactsImportTour({ alreadySeen }: { alreadySeen: boolean }) {
  return <ScreenTour tourKey="contacts_import" steps={STEPS} alreadySeen={alreadySeen} />;
}
