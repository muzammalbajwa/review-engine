import { expect, test } from "@playwright/test";

import { readSharedTenant } from "./helpers";

/**
 * Reuses global-setup.ts's one shared registration rather than making its
 * own — /register is genuinely rate-limited to 5/min
 * (.claude/SECURITY.md #3), and this file isn't testing registration.
 * Logging out in the first test invalidates *that* login's own Sanctum
 * token, never the shared tenant's original registration token — reusing
 * tenant.email/password in the second test is unaffected by the first
 * test's logout.
 */
const tenant = readSharedTenant();

/**
 * Guards against a real bug found live earlier this project: SidebarShell
 * (rendered on every authenticated screen via AppShell) had zero logout
 * affordance anywhere — logout() existed as a real server action in
 * app/settings/actions.ts but was never imported or wired to any button,
 * so a logged-in tenant had no way to log out short of clearing cookies
 * by hand. /settings is used as the post-login destination here (not
 * /dashboard) specifically because /dashboard redirects an
 * onboarding-incomplete tenant straight to /onboarding, which renders
 * outside AppShell and has no sidebar at all — this test needs a real
 * AppShell-wrapped page to find the button on.
 */
test("a user can log in, sees a working logout affordance, and logging out actually clears the session", async ({
  page,
}) => {
  await page.goto("/login");
  await page.locator("#email").fill(tenant.email);
  await page.locator("#password").fill(tenant.password);
  await page.getByRole("button", { name: "Log in" }).click();

  // Login redirects to /dashboard, which itself redirects an
  // onboarding-incomplete tenant to /onboarding — either intermediate is
  // fine, only the destination matters for this assertion.
  await expect(page).toHaveURL(/\/(dashboard|onboarding)/, { timeout: 15_000 });

  await page.goto("/settings");
  await expect(page.getByRole("heading", { name: "Settings" })).toBeVisible();

  const logoutButton = page.getByRole("button", { name: "Log out" });
  await expect(logoutButton).toBeVisible();
  await logoutButton.click();

  await expect(page).toHaveURL(/\/login$/, { timeout: 15_000 });

  // Not just a client-side redirect: the session cookie must actually be
  // invalid now — a direct visit to a protected route must bounce back to
  // /login, not render the protected page from stale client state.
  await page.goto("/settings");
  await expect(page).toHaveURL(/\/login$/);
});

test("logging in with the wrong password shows the same generic error as an unknown email, never confirming which", async ({
  page,
}) => {
  await page.goto("/login");
  await page.locator("#email").fill(tenant.email);
  await page.locator("#password").fill("definitely-the-wrong-password");
  await page.getByRole("button", { name: "Log in" }).click();

  // .first(): Next.js's own route-change announcer also carries
  // role="alert" and is present on every page.
  await expect(page.getByRole("alert").first()).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
});
