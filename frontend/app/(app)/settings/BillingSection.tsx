"use client";

import { useState } from "react";

import { Switch } from "@/components/ui/switch";
import { toggleAutoRenew, type Subscription } from "./actions";
import { SubscribeForm } from "./SubscribeForm";

// "standard" is the only real plan key (.claude/BILLING.md's single-plan
// pricing change). Anything else is a pre-migration tenant on an old
// tier — shown as-is rather than crashing: nothing rewrites their
// plan/status silently.
const PLAN_NAMES: Record<string, string> = {
  standard: "ReviewEngine",
};

const STATUS_LABELS: Record<Exclude<Subscription, null>["status"], string> = {
  pending: "No plan yet",
  trialing: "Free trial",
  active: "Active",
  trial_expired: "Trial expired",
  // Not alarming — .claude/DESIGN.md's destructive red is reserved for
  // an actual compliance-blocked state or a real error; a payment Paddle
  // is still retrying is neither (Tenant::sendingBlockedReason() doesn't
  // block past_due — .claude/BILLING.md's dunning design).
  past_due: "Payment failed — retrying",
  canceled: "Canceled",
};

const INTERVAL_LABELS: Record<"monthly" | "annual", string> = {
  monthly: "Monthly",
  annual: "Annual",
};

export function BillingSection({
  subscription,
  contactsTotal,
  requestsSentTotal,
}: {
  subscription: Subscription;
  contactsTotal: number | null;
  requestsSentTotal: number | null;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // toggleAutoRenew's response is the freshest read of auto_renew/
  // current_period_end (it just made the real Paddle call) — held
  // locally so the switch and its date line update immediately, without
  // waiting for the parent Server Component to re-fetch on next navigation.
  const [renewal, setRenewal] = useState({
    auto_renew: subscription?.auto_renew ?? null,
    current_period_end: subscription?.current_period_end ?? null,
  });

  async function handleAutoRenewChange(checked: boolean) {
    setPending(true);
    setError(null);

    const result = await toggleAutoRenew(checked);

    setPending(false);

    if (result.status === "error") {
      setError(result.message);
      return;
    }

    setRenewal({
      auto_renew: result.subscription?.auto_renew ?? null,
      current_period_end: result.subscription?.current_period_end ?? null,
    });
  }

  const status = subscription?.status ?? "pending";
  // "canceled" needs a fresh checkout the same as never having
  // subscribed — Paddle's own real subscription is gone, there's nothing
  // left to toggle. "active"/"past_due" both have a real, live Paddle
  // subscription underneath (Paddle is still retrying a past_due one,
  // not treating it as ended) — both get the management view below, not
  // the conversion form.
  const needsConversion = status !== "active" && status !== "past_due";
  const isPastDue = status === "past_due";

  return (
    <div className="flex max-w-md flex-col gap-6">
      <div>
        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Current plan</p>
        <div className="mt-2 flex flex-col gap-1 text-sm">
          <p className="text-foreground">
            <span className="text-base font-semibold">
              {subscription?.plan ? (PLAN_NAMES[subscription.plan] ?? subscription.plan) : "Plan"}
            </span>{" "}
            <span className={status === "trial_expired" ? "text-destructive" : "text-muted-foreground"}>
              — {STATUS_LABELS[status]}
              {subscription?.billing_interval && ` (${INTERVAL_LABELS[subscription.billing_interval]})`}
            </span>
          </p>
          {status === "active" && renewal.current_period_end && (
            <p className={renewal.auto_renew ? "text-muted-foreground" : "text-destructive"}>
              {renewal.auto_renew
                ? `Renews automatically on ${formatDate(renewal.current_period_end)}`
                : `Ends on ${formatDate(renewal.current_period_end)} — renew manually to keep access.`}
            </p>
          )}
          {isPastDue && (
            <p role="status" className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-foreground">
              Your last payment didn&apos;t go through. Paddle is automatically retrying — sending review requests
              still works while this resolves.
              {renewal.current_period_end && ` Next retry by ${formatDate(renewal.current_period_end)}.`}
            </p>
          )}
          {status === "trialing" && subscription?.trial_ends_at && (
            <p className="text-muted-foreground">Trial ends {formatDate(subscription.trial_ends_at)}</p>
          )}
          {status === "trial_expired" && (
            <p className="text-destructive">
              Your trial ended — subscribe below to keep sending review requests.
            </p>
          )}
          {status === "canceled" && (
            <p className="text-destructive">
              Your subscription ended — subscribe below to keep sending review requests.
            </p>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4 border-t border-border pt-5">
        <div>
          <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Contacts imported</p>
          <p className="mt-1 text-xl font-semibold tabular-nums text-foreground">
            {contactsTotal ?? "—"}
          </p>
        </div>
        <div>
          <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Requests sent</p>
          <p className="mt-1 text-xl font-semibold tabular-nums text-foreground">
            {requestsSentTotal ?? "—"}
          </p>
        </div>
      </div>

      <div className="border-t border-border pt-5">
        {needsConversion ? (
          <SubscribeForm currentInterval={subscription?.billing_interval ?? null} />
        ) : (
          <>
            <div className="flex items-center justify-between gap-4">
              <div>
                <p className="text-sm font-medium text-foreground">Auto-renew</p>
                <p className="text-xs text-muted-foreground">
                  {renewal.auto_renew
                    ? "Your subscription renews automatically."
                    : "Your subscription won’t renew — access continues until the date above."}
                </p>
              </div>
              <Switch
                checked={renewal.auto_renew ?? false}
                disabled={pending || renewal.auto_renew === null}
                onCheckedChange={handleAutoRenewChange}
                aria-label="Auto-renew"
              />
            </div>
            {error && (
              <p role="alert" className="mt-2 text-sm text-destructive">
                {error}
              </p>
            )}
            <p className="mt-4 text-xs text-muted-foreground">
              To update your card or view invoices, use the link Paddle emailed you at checkout — a billing portal
              isn&apos;t built into this page yet.
            </p>
          </>
        )}
      </div>
    </div>
  );
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString();
}
