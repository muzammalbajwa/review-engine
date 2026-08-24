import type { Metadata } from "next";

import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/session";
import type { Tenant } from "../../settings/actions";
import { ApiDocsContent } from "./ApiDocsContent";
import { ApiDocsTeaser } from "./ApiDocsTeaser";

export const metadata: Metadata = {
  title: "Webhook API — ReviewEngine developer docs",
  description: "Authenticate, create contacts, and handle idempotency and rate limits on the ReviewEngine webhook API.",
};

/**
 * Reuses the same gate RequireSendingAccess/Tenant::sendingBlocked()
 * already enforce server-side for quick-add/webhook contact creation
 * (backend/app/Http/Middleware/RequireSendingAccess.php) — the same
 * tenants.status column, read fresh on every request, no separate
 * feature-flag table or parallel definition of "can this tenant actually
 * use the product." "Subscriber" here is narrower than sendingBlocked()'s
 * own check (which blocks trial_expired and canceled, but not
 * past_due — .claude/BILLING.md's dunning design): a canceled tenant can
 * still technically hit the webhook endpoint today, but showing them
 * full integration docs for an endpoint their billing isn't backing
 * would be misleading. past_due IS included here (unlike trial_expired/
 * canceled) precisely because sendingBlockedReason() doesn't block it
 * either — a temporarily-failed-but-retrying payment still backs real
 * sending access, so the docs should match what they can actually do.
 */
function isSubscriberStatus(status: Tenant["status"]): boolean {
  return status === "trialing" || status === "active" || status === "past_due";
}

/**
 * Server-side gate, not a client-side redirect: getToken()/apiFetch()
 * both run on the server, and ApiDocsContent (the real docs body) is a
 * plain Server Component that's only ever called inside the subscriber
 * branch below. A non-subscriber's response is built exclusively from
 * ApiDocsTeaser's own markup — the full docs JSX is never produced, so
 * it's never in the RSC payload/HTML for that request, regardless of
 * what a visitor inspects via view-source or the network tab.
 */
export default async function ApiDocsPage() {
  const token = await getToken();

  let isSubscriber = false;

  if (token !== null) {
    const tenantResult = await apiFetch<Tenant>("/tenant");
    isSubscriber = tenantResult.ok && isSubscriberStatus(tenantResult.data.status);
  }

  if (!isSubscriber) {
    return <ApiDocsTeaser isLoggedIn={token !== null} />;
  }

  return <ApiDocsContent />;
}
