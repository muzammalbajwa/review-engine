import { expect, test } from "@playwright/test";

/**
 * Guards against a real bug that took down the entire /register page this
 * project already hit once: app/register/actions.ts is a "use server"
 * file, and Next.js disallows exporting anything from such a file except
 * async functions. An earlier version exported a plain
 * `registerInitialState` const object alongside `register()` — Next.js's
 * own compiler rejected the whole module, and every visit to /register
 * hard-crashed before the form ever rendered. See RegisterForm.tsx's own
 * comment for where the initial-state value actually lives now (kept
 * client-side specifically to avoid re-triggering this).
 *
 * This test can only pass if Next.js successfully compiles and serves
 * actions.ts without violating that rule — driving a real registration
 * through the real running dev server *is* the regression guard here,
 * not a separate assertion about the file's exports. Proven live: see the
 * conversation record for the reintroduce-the-bug-confirm-it-fails-revert
 * pass this test was put through before being considered done.
 *
 * .describe.serial + a shared email, not two independently-registered
 * tenants: /register is genuinely rate-limited to 5/min
 * (.claude/SECURITY.md #3, throttle:5,1) — real, correct, load-bearing
 * security behavior, not something to work around by hitting it twice as
 * often as necessary. The second test below reuses the first test's own
 * freshly-created email specifically *because* it needs an email that's
 * already registered — one real POST /register per test, two total for
 * this file, not three.
 */
test.describe.serial("registration", () => {
  const stamp = Date.now();
  const email = `playwright-register-${stamp}@example.com`;

  test("a new user can register through the real form and lands on the guided onboarding flow", async ({ page }) => {
    await page.goto("/register");

    await page.locator("#name").fill("Playwright Test Owner");
    await page.locator("#business_name").fill(`Playwright Register Co ${stamp}`);
    await page.locator("#email").fill(email);
    await page.locator("#password").fill("correct-horse-battery-staple");
    await page.locator("#password_confirmation").fill("correct-horse-battery-staple");

    await page.getByRole("button", { name: "Create account" }).click();

    await expect(page).toHaveURL(/\/onboarding/, { timeout: 15_000 });
    await expect(page.getByRole("heading", { name: "Let's get you set up" })).toBeVisible();
  });

  test("registering again with that same email shows a clear inline error, not a crash", async ({ page }) => {
    await page.goto("/register");
    await page.locator("#name").fill("Second Attempt");
    await page.locator("#business_name").fill("Second Attempt Co");
    await page.locator("#email").fill(email);
    await page.locator("#password").fill("correct-horse-battery-staple");
    await page.locator("#password_confirmation").fill("correct-horse-battery-staple");

    await page.getByRole("button", { name: "Create account" }).click();

    // Two role="alert" elements legitimately render here — RegisterForm
    // shows the same backend message both as the email field's own
    // per-field error and as the form-level error — .first() is enough to
    // prove the message reached the page at all.
    await expect(page.getByRole("alert").first()).toContainText("already registered");
    // Still on /register — a crash or an unexpected redirect would both be
    // wrong here, only a same-page inline error is correct.
    await expect(page).toHaveURL(/\/register$/);
  });
});
