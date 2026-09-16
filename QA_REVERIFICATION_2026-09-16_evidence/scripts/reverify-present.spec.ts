import { expect, test } from "@playwright/test";
import { readFileSync, writeFileSync } from "node:fs";

import { injectSession } from "./helpers";

const EVID = "/Users/wasiq/Desktop/development/code/ReviewEngine/QA_REVERIFICATION_2026-09-16_evidence";

test("F6 + F7 (credentials present): GBP connect leaves for Google; checkout note hidden, button enabled", async ({ page, context }) => {
  const tenant = JSON.parse(readFileSync(`${EVID}/playwright-measurements-part2.json`, "utf-8")).f4_nonadmin_tenant;
  await injectSession(context, tenant);

  await page.goto("/gbp/connect");
  const nav = page.waitForRequest((r) => r.url().startsWith("https://accounts.google.com/"), { timeout: 15_000 });
  await page.getByRole("button", { name: "Connect Google Business Profile" }).click();
  const googleUrl = new URL((await nav).url());
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${EVID}/f6-present-google-page.png` });

  await page.goto("/settings");
  await page.getByRole("button", { name: "Billing" }).click();
  await page.waitForTimeout(1500);
  const noteVisible = await page.getByText("Checkout is temporarily unavailable").isVisible();
  const button = page.getByRole("button", { name: "Continue to checkout" });
  const enabled = await button.isEnabled();
  await page.screenshot({ path: `${EVID}/f7-present-checkout.png`, fullPage: true });

  await button.click();
  await page.waitForTimeout(3000);
  const afterClickAlert = (await page.getByRole("alert").allInnerTexts()).join(" | ");
  await page.screenshot({ path: `${EVID}/f7-present-after-click.png`, fullPage: true });

  writeFileSync(
    `${EVID}/playwright-measurements-part3-present.json`,
    JSON.stringify(
      {
        f6_present: {
          googleHost: googleUrl.host,
          client_id: googleUrl.searchParams.get("client_id"),
          hasState: googleUrl.searchParams.has("state"),
          scope: googleUrl.searchParams.get("scope"),
        },
        f7_present: { noteVisible, enabled, afterClickAlert },
      },
      null,
      2
    )
  );

  expect(googleUrl.host).toBe("accounts.google.com");
  expect(noteVisible).toBe(false);
  expect(enabled).toBe(true);
});
