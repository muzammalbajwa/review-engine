"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { tryContactsRequest, type TryItResult } from "./actions";

/**
 * A real request against the visitor's own account — not a sandbox.
 * .claude/API.md has no test-mode/sandbox concept for the webhook API at
 * all (config/plans.php, WebhookContactController — nothing resembling a
 * dry-run flag exists), so building a fake one here would teach a
 * behavior the real API doesn't have. Disclosed plainly instead of
 * skipped silently.
 */
export function TryItPanel() {
  const [apiKey, setApiKey] = useState("");
  const [name, setName] = useState("Ada Lovelace");
  const [phone, setPhone] = useState("+15555550123");
  const [email, setEmail] = useState("");
  const [externalId, setExternalId] = useState("");
  const [sending, setSending] = useState(false);
  const [result, setResult] = useState<TryItResult | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSending(true);
    setResult(null);

    const response = await tryContactsRequest(apiKey, { name, phone, email, external_id: externalId });

    setSending(false);
    setResult(response);
  }

  const statusOk = result?.ok && result.status >= 200 && result.status < 300;

  return (
    <div className="flex flex-col gap-4 rounded-lg border border-border bg-card p-5">
      <p className="text-sm text-muted-foreground">
        Sends a real <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">POST /api/v1/contacts</code>{" "}
        using the key you paste below — straight to your account, no sandbox or test mode. A successful request
        creates a real contact. The key is sent directly to the API and isn&apos;t stored anywhere.
      </p>

      <form onSubmit={handleSubmit} className="flex flex-col gap-3">
        <Field label="API key">
          <input
            type="text"
            required
            value={apiKey}
            onChange={(e) => setApiKey(e.target.value)}
            placeholder="paste a key from Settings → API keys"
            className="w-full rounded-lg border border-input bg-background px-3 py-1.5 font-mono text-sm text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
          />
        </Field>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="name">
            <input
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full rounded-lg border border-input bg-background px-3 py-1.5 text-sm text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </Field>
          <Field label="phone">
            <input
              type="text"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              className="w-full rounded-lg border border-input bg-background px-3 py-1.5 text-sm text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </Field>
          <Field label="email (optional)">
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-lg border border-input bg-background px-3 py-1.5 text-sm text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </Field>
          <Field label="external_id (optional)">
            <input
              type="text"
              value={externalId}
              onChange={(e) => setExternalId(e.target.value)}
              placeholder="e.g. crm-lead-48213"
              className="w-full rounded-lg border border-input bg-background px-3 py-1.5 font-mono text-sm text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </Field>
        </div>

        <p className="text-xs text-muted-foreground">
          phone or email is required — leave phone blank if you&apos;d rather test with just an email.
        </p>

        <div>
          <Button type="submit" disabled={sending}>
            {sending ? "Sending…" : "Send request"}
          </Button>
        </div>
      </form>

      {result && (
        <div className="flex flex-col gap-2 border-t border-border pt-4">
          {result.ok ? (
            <>
              <div className="flex items-center gap-2">
                <span
                  className={`rounded-full px-2 py-0.5 font-mono text-xs font-medium ${
                    statusOk ? "bg-success/10 text-success" : "bg-destructive/10 text-destructive"
                  }`}
                >
                  {result.status}
                </span>
                <span className="text-xs text-muted-foreground">response</span>
              </div>
              <pre className="overflow-x-auto rounded-lg bg-code p-4 font-mono text-[0.8rem] leading-relaxed text-code-foreground">
                {JSON.stringify(result.body, null, 2)}
              </pre>
            </>
          ) : (
            <p role="alert" className="text-sm text-destructive">
              {result.message}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1">
      <span className="font-mono text-xs text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}
