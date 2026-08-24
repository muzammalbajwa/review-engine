import { expect, test } from "@playwright/test";

import { injectSession, registerRealTenant, type TestTenant } from "./helpers";

/**
 * The first-login welcome tour (SidebarShell + WelcomeTour + Tour).
 * has_completed_welcome_tour is server-side, per-user — the actual thing
 * under test here: a brand-new account must see the tour automatically,
 * exactly once, and a returning account must never see it again unless
 * it's explicitly replayed via "Take the tour again."
 *
 * Registers its own tenant rather than reusing global-setup.ts's shared
 * one — this suite needs to control onboarding-completion precisely
 * (the tour only ever auto-launches post-onboarding). .describe.serial +
 * one registration for the whole file, same rate-limit-conscious
 * reasoning as registration.spec.ts: POST /register is genuinely
 * throttled to 5/min (.claude/SECURITY.md #3), and neither test below is
 * testing registration itself.
 */
test.describe.serial("welcome tour", () => {
  let tenant: TestTenant;

  test.beforeAll(async ({ request }) => {
    tenant = await registerRealTenant(request, "welcome-tour");

    // Dashboard redirects to /onboarding until this is set — the tour
    // only ever matters post-onboarding, and driving the full multi-step
    // wizard through the UI isn't what's under test in this file.
    const complete = await request.post("http://127.0.0.1:8123/api/v1/onboarding/complete", {
      headers: { Authorization: `Bearer ${tenant.token}` },
    });
    expect(complete.ok()).toBe(true);
  });

  test.beforeEach(async ({ context }) => {
    await injectSession(context, tenant);
  });

  test("a brand-new account sees the welcome tour automatically, exactly once", async ({ page, request }) => {
    await page.goto("/dashboard");

    await expect(page.getByText("Welcome to ReviewEngine")).toBeVisible({ timeout: 15_000 });

    await page.getByRole("button", { name: "Skip" }).click();
    await expect(page.getByText("Welcome to ReviewEngine")).not.toBeVisible();

    // The real, persisted effect — not just a client-side dismiss. Same
    // "also verify through the real authenticated API" convention this
    // suite's quick-add.spec.ts already uses.
    const status = await request.get("http://127.0.0.1:8123/api/v1/tours/status", {
      headers: { Authorization: `Bearer ${tenant.token}` },
    });
    expect(status.ok()).toBe(true);
    const statusBody = await status.json();
    expect(statusBody.data.has_completed_welcome_tour).toBe(true);

    // A fresh reload — same account, same tab — never re-triggers it.
    await page.reload();
    await page.waitForTimeout(1000);
    await expect(page.getByText("Welcome to ReviewEngine")).not.toBeVisible();
  });

  test("a returning account never sees it again except by explicitly replaying it", async ({ page }) => {
    await page.goto("/dashboard");

    // Confirms the previous test's completion truly persisted server-side
    // — this is a brand new Page/navigation, not shared in-memory state.
    await page.waitForTimeout(1000);
    await expect(page.getByText("Welcome to ReviewEngine")).not.toBeVisible();

    await page.getByRole("button", { name: "Take the tour again" }).click();

    await expect(page.getByText("Welcome to ReviewEngine")).toBeVisible({ timeout: 15_000 });
  });
});
