import { request as playwrightRequest } from "@playwright/test";
import { writeFileSync } from "node:fs";
import { join } from "node:path";

import { registerRealTenant } from "./helpers";

/**
 * One real POST /register for the *entire suite*, not one per file.
 * /register is genuinely rate-limited to 5/min (.claude/SECURITY.md #3,
 * throttle:5,1) — real, correct, load-bearing security behavior, and this
 * suite ran into it directly the first time each file registered its own
 * tenant (5 real calls landing inside the same ~11-second run blew
 * straight through the cap). registration.spec.ts is the one file that's
 * actually testing registration and still performs its own two real
 * calls; every other file reads the tenant this creates once, here.
 */
export default async function globalSetup(): Promise<void> {
  const context = await playwrightRequest.newContext();

  const tenant = await registerRealTenant(context, "shared");

  writeFileSync(join(__dirname, ".shared-tenant.json"), JSON.stringify(tenant, null, 2));

  await context.dispose();
}
