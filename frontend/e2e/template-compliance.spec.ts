import { expect, test } from "@playwright/test";

import { injectSession, readSharedTenant } from "./helpers";

/**
 * .claude/FRONTEND.md screen 2: "live compliance check with inline
 * pass/block." This project has already found and fixed one real "silent
 * failure" bug in exactly this checker (a failed check that showed
 * nothing / stale state instead of an honest error) — the test below
 * guards against that exact regression class.
 *
 * ONLY the ai_unavailable path is covered here, deliberately, not
 * pass/block. No real ANTHROPIC_API_KEY exists in this dev environment
 * (confirmed against backend/.env throughout this project), so a real
 * request to POST /templates/check ALWAYS resolves to ai_unavailable
 * regardless of the submitted text's content — TemplateController::check()
 * catches any RequestException from the real (unauthenticated, since no
 * key) call to Anthropic and converts it to this exact response. That
 * means pass/block genuinely cannot be exercised end-to-end against the
 * real backend right now — synthesizing them would mean either faking the
 * Next.js server's own outbound fetch (no browser-level interception
 * reaches a Server Action's server-to-server call) or standing up a
 * second mock backend, both disproportionate for two states the backend's
 * own Pest suite (TemplateComplianceTest.php) already covers for real via
 * Http::fake(). Flagged here rather than quietly skipped or faked.
 */
test("an unreachable compliance checker shows an honest inline error, never silence or a stale badge", async ({
  page,
  context,
}) => {
  const tenant = readSharedTenant();
  await injectSession(context, tenant);

  await page.goto("/templates");
  await expect(page.getByRole("heading", { name: "Message templates" })).toBeVisible();

  const textarea = page.locator("#template-body");
  await textarea.fill("A brand new message that has never been checked before, to force a fresh check.");

  // The check is debounced 600ms after the last keystroke, then makes a
  // real request to the real backend, which makes a real request to
  // Anthropic with no real key — genuinely slow, not an arbitrary pad.
  // .first(): Next.js's own route-change announcer
  // (#__next-route-announcer__) also carries role="alert" and is present
  // on every page — .getByRole("alert") alone matches both it and the
  // real inline error; the real one renders first in DOM order.
  await expect(page.getByRole("alert").first()).toContainText(
    "Could not check compliance right now. Try again shortly.",
    { timeout: 15_000 }
  );

  // Not a leftover/stale badge from before the edit — the previous
  // pass/block indicator must be gone, not just covered by the error.
  await expect(page.getByText("Compliant.")).not.toBeVisible();
});
