import { request as playwrightRequest, type APIRequestContext } from "@playwright/test";
import { writeFileSync } from "node:fs";
import { join } from "node:path";

import { BACKEND_URL, registerRealTenant, type TestTenant } from "./helpers";

const MAILPIT_URL = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";

/**
 * One real POST /register for the *entire suite*, not one per file.
 * /register is genuinely rate-limited to 5/min (.claude/SECURITY.md #3,
 * throttle:5,1) — real, correct, load-bearing security behavior, and this
 * suite ran into it directly the first time each file registered its own
 * tenant (5 real calls landing inside the same ~11-second run blew
 * straight through the cap). registration.spec.ts is the one file that's
 * actually testing registration and still performs its own real calls;
 * every other file reads the tenant this creates once, here.
 *
 * The shared tenant is then verified the way a real user does it: the
 * verification email is read from Mailpit and its signed link opened.
 * Unverified tenants can't send, so quick-add and template-compliance
 * can't pass without this. Needs Mailpit plus either a queue worker or
 * QUEUE_CONNECTION=sync on the backend, and APP_URL set to the backend's
 * real address so the signed link resolves.
 */
export default async function globalSetup(): Promise<void> {
  const context = await playwrightRequest.newContext();

  const tenant = await registerRealTenant(context, "shared");
  await verifyViaEmail(context, tenant);

  writeFileSync(join(__dirname, ".shared-tenant.json"), JSON.stringify(tenant, null, 2));

  await context.dispose();
}

async function verifyViaEmail(context: APIRequestContext, tenant: TestTenant): Promise<void> {
  const link = await waitForVerificationLink(context, tenant.email);

  const response = await context.get(link, { maxRedirects: 0 });
  const location = response.headers()["location"] ?? "";

  if (response.status() !== 302 || !location.includes("verified=1")) {
    throw new Error(`Opening the verification link failed: ${response.status()} ${location}`);
  }

  const tenantResponse = await context.get(`${BACKEND_URL}/tenant`, {
    headers: { Authorization: `Bearer ${tenant.token}`, Accept: "application/json" },
  });
  const body = (await tenantResponse.json()) as { data?: { email_verified?: boolean } };

  if (body.data?.email_verified !== true) {
    throw new Error(`Shared tenant is not verified after opening the link: ${tenantResponse.status()} ${JSON.stringify(body)}`);
  }
}

async function waitForVerificationLink(context: APIRequestContext, email: string): Promise<string> {
  const deadline = Date.now() + 30_000;

  while (Date.now() < deadline) {
    const search = await context.get(`${MAILPIT_URL}/api/v1/search`, { params: { query: `to:"${email}"` } });

    if (search.ok()) {
      const { messages } = (await search.json()) as { messages: { ID: string; Subject: string }[] };
      const message = messages.find((m) => m.Subject === "Verify Email Address");

      if (message) {
        const full = await context.get(`${MAILPIT_URL}/api/v1/message/${message.ID}`);
        const { Text } = (await full.json()) as { Text: string };
        const link = Text.match(/https?:\/\/\S+\/api\/v1\/email\/verify\/[^\s)\]]+/)?.[0];

        if (!link) {
          throw new Error(`Verification email for ${email} has no verify link:\n${Text}`);
        }

        return link;
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  throw new Error(
    `No verification email for ${email} reached Mailpit (${MAILPIT_URL}) within 30s. ` +
      "Is Mailpit running, and is a queue worker running (or QUEUE_CONNECTION=sync)?"
  );
}
