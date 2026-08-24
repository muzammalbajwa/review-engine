"use client";

import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { issueApiKey, type ApiKey } from "./actions";

/**
 * The webhook API's key management UI — the backend (GET/POST
 * /api/v1/api-keys) has existed since Step 2 with no UI until now. A
 * plaintext key is only ever visible in the response to the POST that
 * issued it (Sanctum hashes tokens at rest, same as every token this app
 * issues) — there's no "reveal" affordance because there's nothing left
 * to reveal after this screen.
 */
export function ApiKeysSection({ initialKeys }: { initialKeys: ApiKey[] }) {
  const [keys, setKeys] = useState(initialKeys);
  const [issuing, setIssuing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [justIssued, setJustIssued] = useState<string | null>(null);

  const hasActiveKey = keys.some((key) => key.status === "active");

  async function handleIssue() {
    setIssuing(true);
    setError(null);
    setJustIssued(null);

    const result = await issueApiKey();
    setIssuing(false);

    if (result.status === "error") {
      setError(result.message);
      return;
    }

    setJustIssued(result.token);
    setKeys((prev) => {
      // gracedKeys carries the real expires_at the backend just wrote for
      // any key(s) this rotation grace-perioded — swapped in directly
      // rather than guessed client-side, so "works until [date]" below is
      // never wrong or missing right after a rotation.
      const gracedById = new Map(result.gracedKeys.map((key) => [key.id, key]));
      const updated = prev.map((key) => gracedById.get(key.id) ?? key);

      return [result.key, ...updated];
    });
  }

  return (
    <div className="flex max-w-xl flex-col gap-6">
      <p className="text-sm text-muted-foreground">
        Used to authenticate the webhook API (<code className="font-mono">POST /api/v1/contacts</code>) —
        the same integration surface any tool that can send a webhook can use. See the{" "}
        <Link href="/docs/api" className="font-medium text-primary underline-offset-4 hover:underline">
          API docs
        </Link>{" "}
        for request/response details.
      </p>

      {justIssued && (
        <div className="flex flex-col gap-2 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm">
          <p className="font-medium text-foreground">Copy this key now — you won&apos;t see it again.</p>
          <code className="overflow-x-auto rounded-lg border border-border bg-background px-3 py-2 font-mono text-xs break-all text-foreground">
            {justIssued}
          </code>
          <div>
            <CopyButton value={justIssued} />
          </div>
        </div>
      )}

      {keys.length === 0 ? (
        <p className="text-sm text-muted-foreground">No API keys yet.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {keys.map((key) => (
            <li
              key={key.id}
              className="flex items-center justify-between rounded-lg border border-border p-3 text-sm"
            >
              <div>
                <p className="text-foreground">
                  Created <time dateTime={key.created_at}>{new Date(key.created_at).toLocaleDateString()}</time>
                </p>
                <p className="text-muted-foreground">
                  {key.last_used_at
                    ? `Last used ${new Date(key.last_used_at).toLocaleString()}`
                    : "Never used yet"}
                  {key.status === "expiring" && key.expires_at && (
                    <> — still works until {new Date(key.expires_at).toLocaleString()}</>
                  )}
                </p>
              </div>
              {key.status === "active" ? (
                <span className="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                  Active
                </span>
              ) : (
                <span className="rounded-full bg-warning px-2 py-0.5 text-xs font-medium text-warning-foreground">
                  Expiring
                </span>
              )}
            </li>
          ))}
        </ul>
      )}

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <div className="border-t border-border pt-5">
        <Button onClick={handleIssue} disabled={issuing} variant={hasActiveKey ? "outline" : "default"}>
          {issuing ? "Generating…" : hasActiveKey ? "Rotate key" : "Generate API key"}
        </Button>
        {hasActiveKey && (
          <p className="mt-2 text-xs text-muted-foreground">
            Your current key keeps working for 24 hours after you rotate, so an in-flight integration
            doesn&apos;t break.
          </p>
        )}
      </div>
    </div>
  );
}

function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    await navigator.clipboard.writeText(value);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <Button type="button" variant="outline" size="sm" onClick={handleCopy}>
      {copied ? "Copied" : "Copy key"}
    </Button>
  );
}
