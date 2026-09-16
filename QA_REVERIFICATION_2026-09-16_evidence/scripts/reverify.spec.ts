import { expect, test, type APIRequestContext, type Page } from "@playwright/test";
import { writeFileSync } from "node:fs";

import { injectSession, registerRealTenant } from "./helpers";

const EVID = "/Users/wasiq/Desktop/development/code/ReviewEngine/QA_REVERIFICATION_2026-09-16_evidence";
const MAILPIT = "http://127.0.0.1:8025";
const log: Record<string, unknown> = {};

test.describe.configure({ mode: "serial" });

test.afterAll(() => {
  writeFileSync(`${EVID}/playwright-measurements.json`, JSON.stringify(log, null, 2));
});

type MailSummary = { ID: string; Subject: string; Created: string; To: { Address: string }[] };

async function waitForMail(request: APIRequestContext, to: string, subject: string, minCount = 1, timeoutMs = 20_000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const res = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}`);
    const body = (await res.json()) as { messages: MailSummary[] };
    const hits = body.messages.filter((m) => m.Subject.includes(subject));
    if (hits.length >= minCount) return { hits, waitedMs: Date.now() - started };
    await new Promise((r) => setTimeout(r, 250));
  }
  throw new Error(`No mail "${subject}" to ${to} within ${timeoutMs}ms`);
}

async function mailText(request: APIRequestContext, id: string): Promise<string> {
  const res = await request.get(`${MAILPIT}/api/v1/message/${id}`);
  return ((await res.json()) as { Text: string }).Text;
}

async function shotMail(page: Page, id: string, name: string) {
  await page.goto(`${MAILPIT}/view/${id}`);
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${EVID}/${name}.png`, fullPage: true });
}

async function fieldValues(page: Page) {
  return {
    name: await page.locator("#name").inputValue(),
    business_name: await page.locator("#business_name").inputValue(),
    email: await page.locator("#email").inputValue(),
    password: await page.locator("#password").inputValue(),
    password_confirmation: await page.locator("#password_confirmation").inputValue(),
  };
}

test("F3 + F1: real registration form, two validation errors, then real verify + welcome mail via worker", async ({ page, request, context }) => {
  const stamp = Date.now();
  const email = `reverify-f1-${stamp}@example.com`;
  const name = "Reverify Owner";
  const business = `Reverify Roofing ${stamp}`;

  await page.goto("/register");
  await page.locator("#name").fill(name);
  await page.locator("#business_name").fill(business);
  await page.locator("#email").fill(email);
  await page.locator("#password").fill("correct-horse-battery-staple");
  await page.locator("#password_confirmation").fill("a-different-password-999");
  await page.getByRole("button", { name: /create/i }).click();
  await expect(page.getByText(/confirmation does not match|do not match|must match/i).first()).toBeVisible({ timeout: 15_000 });
  const afterMismatch = await fieldValues(page);
  await page.screenshot({ path: `${EVID}/f3-error1-password-mismatch.png`, fullPage: true });

  await page.locator("#email").fill("admin@reviewengine.test");
  await page.locator("#password").fill("correct-horse-battery-staple");
  await page.locator("#password_confirmation").fill("correct-horse-battery-staple");
  await page.getByRole("button", { name: /create/i }).click();
  await expect(page.getByText("This email is already registered.").first()).toBeVisible({ timeout: 15_000 });
  const afterDuplicate = await fieldValues(page);
  await page.screenshot({ path: `${EVID}/f3-error2-duplicate-email.png`, fullPage: true });

  log.f3 = { afterMismatch, afterDuplicate };
  expect(afterMismatch).toMatchObject({ name, business_name: business, email, password: "", password_confirmation: "" });
  expect(afterDuplicate).toMatchObject({ name, business_name: business, email: "admin@reviewengine.test", password: "", password_confirmation: "" });

  await page.locator("#email").fill(email);
  await page.locator("#password").fill("correct-horse-battery-staple");
  await page.locator("#password_confirmation").fill("correct-horse-battery-staple");
  const submittedAt = Date.now();
  await page.getByRole("button", { name: /create/i }).click();
  await expect(page).toHaveURL(/\/onboarding/, { timeout: 15_000 });

  const verify = await waitForMail(request, email, "Verify Email Address");
  const text = await mailText(request, verify.hits[0].ID);
  const link = text.match(/http:\/\/127\.0\.0\.1:8123\/api\/v1\/email\/verify\/[^\s)\]]+/)?.[0];
  expect(link, "verification link in the real email").toBeTruthy();

  const mailPage = await context.newPage();
  await shotMail(mailPage, verify.hits[0].ID, "f1-verify-email-in-mailpit");

  await page.goto(link!);
  await page.waitForLoadState("networkidle");
  const landedAfterVerify = page.url();
  await page.screenshot({ path: `${EVID}/f1-after-clicking-verify-link.png`, fullPage: true });

  const welcome = await waitForMail(request, email, "You're verified");
  await shotMail(mailPage, welcome.hits[0].ID, "f1-welcome-email-in-mailpit");

  log.f1 = {
    email,
    verifyMail: { id: verify.hits[0].ID, created: verify.hits[0].Created, msFromSubmit: Date.now() - submittedAt },
    verifyLinkHost: new URL(link!).host,
    landedAfterVerify,
    welcomeMail: { id: welcome.hits[0].ID, created: welcome.hits[0].Created, subject: welcome.hits[0].Subject },
  };
});

test("F1: 'Resend verification email' button delivers a second real email via worker", async ({ page, context, request }) => {
  const tenant = await registerRealTenant(request, "reverify-resend");
  await waitForMail(request, tenant.email, "Verify Email Address", 1);
  await injectSession(context, tenant);
  await page.goto("/settings");
  await page.getByRole("button", { name: "Resend verification email" }).click();
  const both = await waitForMail(request, tenant.email, "Verify Email Address", 2);
  await shotMail(page, both.hits[0].ID, "f1-resend-verify-email-in-mailpit");
  log.f1_resend = { email: tenant.email, verifyMailCount: both.hits.length, ids: both.hits.map((h) => h.ID) };
  log.f4_nonadmin_tenant = tenant;
});

test("F4: seeded super-admin lands on /admin; fresh non-admin still lands on /onboarding", async ({ browser }) => {
  const adminCtx = await browser.newContext({ ignoreHTTPSErrors: true, baseURL: "https://localhost:3000" });
  const admin = await adminCtx.newPage();
  await admin.goto("/login");
  await admin.locator("#email").fill("admin@reviewengine.test");
  await admin.locator("#password").fill("DemoAdmin123!");
  await admin.getByRole("button", { name: "Log in" }).click();
  await admin.waitForURL(/\/(admin|onboarding|dashboard)/, { timeout: 15_000 });
  await admin.waitForLoadState("networkidle");
  const adminUrl = admin.url();
  await admin.screenshot({ path: `${EVID}/f4-admin-after-login.png`, fullPage: true });

  const t = log.f4_nonadmin_tenant as { email: string; password: string };
  const userCtx = await browser.newContext({ ignoreHTTPSErrors: true, baseURL: "https://localhost:3000" });
  const user = await userCtx.newPage();
  await user.goto("/login");
  await user.locator("#email").fill(t.email);
  await user.locator("#password").fill(t.password);
  await user.getByRole("button", { name: "Log in" }).click();
  await user.waitForURL(/\/(admin|onboarding|dashboard)/, { timeout: 15_000 });
  await user.waitForLoadState("networkidle");
  const userUrl = user.url();
  await user.screenshot({ path: `${EVID}/f4-nonadmin-after-login.png`, fullPage: true });

  const adminOnboarding = await adminCtx.newPage();
  await adminOnboarding.goto("/dashboard");
  await adminOnboarding.waitForLoadState("networkidle");
  const adminDirectDashboard = adminOnboarding.url();

  log.f4 = { adminUrl, userUrl, adminDirectDashboard };
  expect(new URL(adminUrl).pathname).toBe("/admin");
  expect(new URL(userUrl).pathname).toBe("/onboarding");
  await adminCtx.close();
  await userCtx.close();
});

test("F2: marketing homepage has no horizontal overflow at 768px (and neighbours)", async ({ page }) => {
  const results: Record<string, unknown> = {};
  for (const width of [360, 390, 640, 700, 768, 820, 1024, 1280]) {
    await page.setViewportSize({ width, height: 1024 });
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    results[width] = await page.evaluate(() => {
      const el = document.documentElement;
      const offenders = [...document.querySelectorAll("body *")]
        .filter((n) => n.getBoundingClientRect().right > el.clientWidth + 0.5)
        .slice(0, 5)
        .map((n) => `${n.tagName.toLowerCase()}.${(n as HTMLElement).className?.toString().slice(0, 40)} right=${n.getBoundingClientRect().right}`);
      return { scrollWidth: el.scrollWidth, clientWidth: el.clientWidth, bodyScrollWidth: document.body.scrollWidth, offenders };
    });
    if (width === 768) {
      await page.screenshot({ path: `${EVID}/f2-home-768-full.png`, fullPage: true });
      await page.locator("footer").screenshot({ path: `${EVID}/f2-footer-768.png` });
    }
  }
  log.f2 = results;
  const r = results[768] as { scrollWidth: number; clientWidth: number };
  expect(r.scrollWidth).toBe(r.clientWidth);
});

test("F6 + F7 (credentials absent): GBP connect inline error, checkout note", async ({ page, context, request }) => {
  const tenant = log.f4_nonadmin_tenant as Parameters<typeof injectSession>[1];
  await injectSession(context, tenant);

  const googleHits: string[] = [];
  page.on("request", (r) => {
    if (r.url().includes("accounts.google.com")) googleHits.push(r.url());
  });
  await page.goto("/onboarding");
  await page.goto("/gbp/connect");
  await page.getByRole("button", { name: "Connect Google Business Profile" }).click();
  await page.waitForURL(/error=/, { timeout: 15_000 });
  const gbpUrl = page.url();
  await page.screenshot({ path: `${EVID}/f6-absent-inline-error.png`, fullPage: true });

  await page.goto("/settings");
  await page.getByRole("button", { name: "Billing" }).click();
  const note = await page.getByText("Checkout is temporarily unavailable").isVisible();
  const disabled = await page.getByRole("button", { name: "Continue to checkout" }).isDisabled();
  await page.screenshot({ path: `${EVID}/f7-absent-checkout-note.png`, fullPage: true });

  log.f6_absent = { gbpUrl, googleHits };
  log.f7_absent = { note, disabled };
  expect(gbpUrl).toContain("error=gbp_not_configured");
  expect(googleHits).toEqual([]);
  expect(note && disabled).toBe(true);
});
