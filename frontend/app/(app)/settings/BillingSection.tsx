"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { openBillingPortal, toggleAutoRenew, type Subscription } from "./actions";
import { SubscribeForm } from "./SubscribeForm";

// 'standard' is the only real plan key (.claude/BILLING.md's single-plan
// pricing change). Anything else is a pre-migration tenant on the old
// Starter/Growth/Pro tiers — shown as-is rather than crashing (BILLING.md's
// "Existing tenants on the old Stripe pricing" section: nothing rewrites
// their plan/status silently).
const PLAN_NAMES: Record<string, string> = {
  standard: "ReviewEngine",
};

const STATUS_LABELS: Record<Exclude<Subscription, null>["status"], string> = {
  pending: "No plan yet",
  trialing: "Free trial",
  active: "Active",
  trial_expired: "Trial expired",
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
  // current_period_end (it just made the real Lemon Squeezy call) — held
  // locally so the switch and its date line update immediately, without
  // waiting for the parent Server Component to re-fetch on next navigation.
  const [renewal, setRenewal] = useState({
    auto_renew: subscription?.auto_renew ?? null,
    current_period_end: subscription?.current_period_end ?? null,
  });
  const [renewalPending, setRenewalPending] = useState(false);
  const [renewalError, setRenewalError] = useState<string | null>(null);

  async function handleManageBilling() {
    setPending(true);
    setError(null);

    const result = await openBillingPortal();
    // A success redirects away entirely — reaching this line at all means
    // it didn't.
    setPending(false);

    if (result?.status === "error") {
      setError(result.message);
    }
  }

  async function handleAutoRenewChange(checked: boolean) {
    setRenewalPending(true);
    setRenewalError(null);

    const result = await toggleAutoRenew(checked);

    setRenewalPending(false);

    if (result.status === "error") {
      setRenewalError(result.message);
      return;
    }

    setRenewal({
      auto_renew: result.subscription?.auto_renew ?? null,
      current_period_end: result.subscription?.current_period_end ?? null,
    });
  }

  const status = subscription?.status ?? "pending";
  // Only a real, active Lemon Squeezy subscription has anything for the
  // hosted portal to manage — trialing/trial_expired/pending/canceled all
  // have no Lemon Squeezy customer yet (GET /subscription/portal would 422
  // no_billing_account), so those states get the conversion form instead.
  const needsConversion = status !== "active";

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
                ? `Renews automatically on ${new Date(renewal.current_period_end).toLocaleDateString()}`
                : `Ends on ${new Date(renewal.current_period_end).toLocaleDateString()} — renew manually to keep access.`}
            </p>
          )}
          {status === "trialing" && subscription?.trial_ends_at && (
            <p className="text-muted-foreground">
              Trial ends {new Date(subscription.trial_ends_at).toLocaleDateString()}
            </p>
          )}
          {status === "trial_expired" && (
            <p className="text-destructive">
              Your trial ended — subscribe below to keep sending review requests.
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
                disabled={renewalPending || renewal.auto_renew === null}
                onCheckedChange={handleAutoRenewChange}
                aria-label="Auto-renew"
              />
            </div>
            {renewalError && (
              <p role="alert" className="mt-2 text-sm text-destructive">
                {renewalError}
              </p>
            )}

            <Button onClick={handleManageBilling} variant="outline" disabled={pending} className="mt-4">
              {pending ? "Opening…" : "Manage billing"}
            </Button>
            <p className="mt-2 text-xs text-muted-foreground">
              Update your card, view invoices, or change plans in Lemon Squeezy&apos;s billing portal.
            </p>
          </>
        )}
        {error && (
          <p role="alert" className="mt-2 text-sm text-destructive">
            {error}
          </p>
        )}
      </div>
    </div>
  );
}
