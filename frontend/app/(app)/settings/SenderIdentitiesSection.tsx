"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { createSenderIdentity, type SenderIdentity } from "./actions";

/**
 * .claude/CLAUDE.md Phase 2 Step 2: a sender identity gates the send job —
 * a message never goes out from an address the tenant hasn't proven they
 * control. First UI this has ever had; the verify flow itself is an email
 * link (App\Notifications\VerifySenderIdentity), not built here.
 */
export function SenderIdentitiesSection({ initialIdentities }: { initialIdentities: SenderIdentity[] }) {
  const [identities, setIdentities] = useState(initialIdentities);
  const [fromName, setFromName] = useState("");
  const [fromEmail, setFromEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [justAdded, setJustAdded] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    setJustAdded(null);

    const result = await createSenderIdentity(fromName, fromEmail);
    setSubmitting(false);

    if (result.status === "error") {
      setError(result.message);
      return;
    }

    setIdentities((prev) => [result.identity, ...prev]);
    setJustAdded(result.identity.from_email);
    setFromName("");
    setFromEmail("");
  }

  return (
    <div className="flex max-w-xl flex-col gap-6">
      <p className="text-sm text-muted-foreground">
        Review requests send from one of these addresses. An address has to be verified — proven you
        control it — before anything can send from it.
      </p>

      {identities.length === 0 ? (
        <p className="text-sm text-muted-foreground">No sender addresses yet. Add one below.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {identities.map((identity) => (
            <li
              key={identity.id}
              className="flex items-center justify-between rounded-lg border border-border p-3 text-sm"
            >
              <div>
                <p className="font-medium text-foreground">{identity.from_name}</p>
                <p className="text-muted-foreground">{identity.from_email}</p>
              </div>
              {identity.verified ? (
                <span className="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                  Verified
                </span>
              ) : (
                // Solid fill, not bg-warning/10: gold text on a near-white
                // tint fails WCAG AA (2.4:1, needs 4.5:1) — see
                // ReviewsList's "Needs reply" pill for the same fix.
                <span className="rounded-full bg-warning px-2 py-0.5 text-xs font-medium text-warning-foreground">
                  Awaiting verification
                </span>
              )}
            </li>
          ))}
        </ul>
      )}

      <form onSubmit={handleSubmit} className="flex flex-col gap-4 border-t border-border pt-5">
        <p className="text-sm font-medium text-foreground">Add a sender address</p>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="sender-name" className="text-xs font-medium text-muted-foreground">
              Name
            </label>
            <input
              id="sender-name"
              type="text"
              required
              value={fromName}
              onChange={(e) => setFromName(e.target.value)}
              placeholder="e.g. Jordan's Roofing"
              className="h-9 rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="sender-email" className="text-xs font-medium text-muted-foreground">
              Email address
            </label>
            <input
              id="sender-email"
              type="email"
              required
              value={fromEmail}
              onChange={(e) => setFromEmail(e.target.value)}
              placeholder="reviews@yourbusiness.com"
              className="h-9 rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </div>
        </div>

        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        {justAdded && (
          <p role="status" className="text-sm text-success">
            Check {justAdded} for a verification link.
          </p>
        )}

        <div>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Adding…" : "Add sender"}
          </Button>
        </div>
      </form>
    </div>
  );
}
