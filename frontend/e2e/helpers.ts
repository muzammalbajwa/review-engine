import type { APIRequestContext, BrowserContext, Page } from "@playwright/test";
import { expect } from "@playwright/test";
import { readFileSync } from "node:fs";
import { join } from "node:path";

/**
 * Same host/port every other real live-verification this project has
 * done against the backend all session (frontend/.env.local's
 * NEXT_PUBLIC_API_URL) — not the never-configured :8443 proxy
 * bootstrap/app.php's comments describe.
 */
export const BACKEND_URL = "http://127.0.0.1:8123/api/v1";

export type TestTenant = {
  email: string;
  password: string;
  businessName: string;
  token: string;
  tenantId: string;
};

/**
 * Real registration against the real running backend, via a raw API call
 * rather than driving the UI form — used as fast setup by tests whose
 * subject is something *other* than the registration form itself
 * (registration.spec.ts exercises that form directly). Every value is
 * timestamp-suffixed to avoid colliding with other runs against the same
 * shared dev database, matching how every manual live check this project
 * has done all session avoids collisions.
 */
export async function registerRealTenant(request: APIRequestContext, label: string): Promise<TestTenant> {
  const stamp = Date.now();
  const email = `playwright-${label}-${stamp}@example.com`;
  const password = "correct-horse-battery-staple";
  const businessName = `Playwright ${label} Co ${stamp}`;

  const response = await request.post(`${BACKEND_URL}/register`, {
    data: {
      name: "Playwright Test Owner",
      business_name: businessName,
      email,
      password,
      password_confirmation: password,
    },
  });

  if (!response.ok()) {
    throw new Error(`Real registration failed in test setup: ${response.status()} ${await response.text()}`);
  }

  const body = await response.json();

  return {
    email,
    password,
    businessName,
    token: body.data.token as string,
    tenantId: body.data.tenant.id as string,
  };
}

/**
 * Establishes a real browser session by actually driving the login form —
 * not cookie injection. Slower than crafting a cookie directly, but that
 * would couple every caller to lib/session.ts's exact cookie name/shape;
 * this only depends on the login form's own public contract, which
 * login-logout.spec.ts already tests directly.
 */
export async function loginViaUi(page: Page, tenant: TestTenant): Promise<void> {
  await page.goto("/login");
  await page.locator("#email").fill(tenant.email);
  await page.locator("#password").fill(tenant.password);
  await page.getByRole("button", { name: "Log in" }).click();
  await expect(page).toHaveURL(/\/(dashboard|onboarding)/, { timeout: 15_000 });
}

/**
 * Establishes a real, valid browser session without driving the login
 * form — for tests whose subject is something other than login itself
 * (login-logout.spec.ts exercises that form directly, including its own
 * /login throttle:5,1 budget). lib/session.ts's own re_token cookie is
 * just the raw Sanctum bearer token, httpOnly+secure+strict — Sanctum
 * validates it the same way regardless of whether it arrived via the
 * login form or was set directly, so this is still a genuinely real
 * session, not a fake one, just without re-spending a second real
 * /login POST on a path this file isn't testing.
 */
export async function injectSession(context: BrowserContext, tenant: TestTenant): Promise<void> {
  await context.addCookies([
    {
      name: "re_token",
      value: tenant.token,
      domain: "localhost",
      path: "/",
      httpOnly: true,
      secure: true,
      sameSite: "Strict",
    },
  ]);
}

/**
 * The one tenant global-setup.ts registers for the whole suite. Reading a
 * file rather than an env var/module singleton: global setup and the
 * actual test files run in separate Node processes under Playwright's
 * default architecture, so nothing written to a plain variable in one
 * would be visible in the other.
 */
export function readSharedTenant(): TestTenant {
  return JSON.parse(readFileSync(join(__dirname, ".shared-tenant.json"), "utf-8")) as TestTenant;
}

/** The real quick-add token for a tenant, read via the real authenticated API. */
export async function fetchQuickAddToken(request: APIRequestContext, tenant: TestTenant): Promise<string> {
  const response = await request.get(`${BACKEND_URL}/tenant`, {
    headers: { Authorization: `Bearer ${tenant.token}` },
  });

  if (!response.ok()) {
    throw new Error(`Fetching quick-add token failed: ${response.status()} ${await response.text()}`);
  }

  const body = await response.json();

  return body.data.quick_add_token as string;
}
