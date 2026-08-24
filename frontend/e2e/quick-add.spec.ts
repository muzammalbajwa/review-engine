import { expect, test } from "@playwright/test";

import { fetchQuickAddToken, readSharedTenant } from "./helpers";

/**
 * .claude/CLAUDE.md's public, no-login link (reviewengine.com/quick/{token})
 * — the one form in this product that must work with zero authentication
 * state at all. This browser context never logs in or carries any
 * session; the page must resolve entirely off the token in the URL.
 *
 * Reuses global-setup.ts's one shared registration rather than making its
 * own — /register is genuinely rate-limited to 5/min
 * (.claude/SECURITY.md #3), and none of the tests below are testing
 * registration itself.
 */
const tenant = readSharedTenant();
let quickAddToken: string;

test.beforeAll(async ({ request }) => {
  quickAddToken = await fetchQuickAddToken(request, tenant);
});

test("a real customer can be added through the public quick-add link with no login at all", async ({
  page,
  request,
}) => {
  await page.goto(`/quick/${quickAddToken}`);

  await expect(page.getByRole("heading", { name: `Add a customer for ${tenant.businessName}` })).toBeVisible();

  const stamp = Date.now();
  await page.locator("#quick-add-name").fill("Priya Shah");
  await page.locator("#quick-add-email").fill(`priya-quickadd-${stamp}@example.com`);

  await page.getByRole("button", { name: "Job completed — start review request" }).click();

  await expect(page.getByText("Priya Shah is on their way to a review request")).toBeVisible({ timeout: 15_000 });

  // The real, persisted effect — not just a friendly client-side message.
  // Requires the tenant's own real session token, confirming this
  // contact genuinely landed against the correct tenant, not silently
  // dropped or misfiled.
  const contactsResponse = await request.get("http://127.0.0.1:8123/api/v1/contacts", {
    headers: { Authorization: `Bearer ${tenant.token}` },
  });
  expect(contactsResponse.ok()).toBe(true);
  const contactsBody = await contactsResponse.json();
  const created = contactsBody.data.data.find((contact: { name: string }) => contact.name === "Priya Shah");
  expect(created).toBeTruthy();
  expect(created.source).toBe("quick_add");
  expect(created.status).toBe("pending");
});

test("an invalid quick-add token shows a clean not-found message, not a crash", async ({ page }) => {
  await page.goto("/quick/this-token-does-not-exist-at-all");

  // .first(): Next.js's own route-change announcer also carries
  // role="alert" and is present on every page.
  await expect(page.getByRole("alert").first()).toContainText(/link isn't active/i);
});

test("submitting with neither phone nor email is blocked client-side — the submit button never enables", async ({
  page,
}) => {
  await page.goto(`/quick/${quickAddToken}`);

  await page.locator("#quick-add-name").fill("No Contact Method");

  await expect(page.getByRole("button", { name: "Job completed — start review request" })).toBeDisabled();
});
