"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { getWebhookActivity, sendTestEvent, type WebhookActivity } from "./actions";

/**
 * .claude/FRONTEND.md: the screen a tenant checks when wondering "is this
 * actually working" — leads with a plain-language status line, not a raw
 * log, and biases every state (including "nothing yet") toward calm,
 * non-alarming language rather than treating an empty result as an error.
 */
export function WebhookActivitySection({
  webhookUrl,
  initialActivity,
}: {
  webhookUrl: string;
  initialActivity: WebhookActivity;
}) {
  const [activity, setActivity] = useState(initialActivity);
  const [refreshing, setRefreshing] = useState(false);
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);

  async function handleRefresh() {
    setRefreshing(true);
    const result = await getWebhookActivity();
    setRefreshing(false);

    if (result.ok) {
      setActivity(result.data);
    }
  }

  async function handleSendTest() {
    setTesting(true);
    setTestResult(null);

    const result = await sendTestEvent();
    setTesting(false);

    setTestResult(
      result.status === "success"
        ? { ok: true, message: `Test contact created (#${result.contactId}) — the endpoint and enrollment pipeline both work.` }
        : { ok: false, message: result.message }
    );
  }

  const mostRecent = activity.recent[0];

  return (
    <div className="flex max-w-xl flex-col gap-6">
      {/* At-a-glance status — the answer to "is this working," before anything else on the page */}
      <div
        className={`rounded-lg border p-4 ${
          activity.count > 0 ? "border-success/30 bg-success/10" : "border-border bg-muted/50"
        }`}
      >
        <p className={`text-sm font-medium ${activity.count > 0 ? "text-success" : "text-foreground"}`}>
          {activity.count > 0
            ? `${activity.count} contact${activity.count === 1 ? "" : "s"} added via webhook in the last ${activity.window_days} days`
            : `No contacts added via webhook in the last ${activity.window_days} days`}
        </p>
        {mostRecent && (
          <p className="mt-1 text-xs text-muted-foreground">
            Most recent: {mostRecent.name}, <time dateTime={mostRecent.created_at}>{new Date(mostRecent.created_at).toLocaleString()}</time>
          </p>
        )}
        {activity.count === 0 && (
          <p className="mt-1 text-xs text-muted-foreground">
            Expected if you haven&apos;t connected Zapier, Make, or a CRM yet — send a test event below to confirm
            the endpoint itself is working in the meantime.
          </p>
        )}
      </div>

      {/* The endpoint itself */}
      <div className="flex flex-col gap-2">
        <span className="font-mono text-xs text-muted-foreground">Webhook URL</span>
        <div className="flex items-center gap-2">
          <code className="flex-1 overflow-x-auto rounded-lg border border-border bg-muted px-3 py-2 font-mono text-xs text-foreground">
            {webhookUrl}
          </code>
          <CopyButton value={webhookUrl} />
        </div>
        <p className="text-xs text-muted-foreground">
          POST here with <code className="rounded bg-muted px-1 py-0.5 font-mono text-[0.7rem]">Authorization: Bearer &lt;api key&gt;</code> —
          full request/response reference at{" "}
          <a href="/docs/api" className="font-medium text-primary underline-offset-4 hover:underline">
            /docs/api
          </a>
          .
        </p>
      </div>

      {/* Send test event */}
      <div className="flex flex-col gap-2 border-t border-border pt-5">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm font-medium text-foreground">Send a test event</p>
            <p className="text-xs text-muted-foreground">
              Creates one real contact using this dashboard session — confirms the endpoint and enrollment work
              end-to-end, even before you&apos;ve generated an API key.
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={handleSendTest} disabled={testing}>
            {testing ? "Sending…" : "Send test event"}
          </Button>
        </div>
        {testResult && (
          <p
            role={testResult.ok ? "status" : "alert"}
            className={`text-sm ${testResult.ok ? "text-success" : "text-destructive"}`}
          >
            {testResult.message}
          </p>
        )}
      </div>

      {/* Recent activity detail */}
      <div className="flex flex-col gap-2 border-t border-border pt-5">
        <div className="flex items-center justify-between">
          <p className="text-sm font-medium text-foreground">Recent webhook activity</p>
          <Button variant="ghost" size="sm" onClick={handleRefresh} disabled={refreshing}>
            {refreshing ? "Refreshing…" : "Refresh"}
          </Button>
        </div>
        {activity.recent.length === 0 ? (
          <p className="text-sm text-muted-foreground">Nothing in the last {activity.window_days} days.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {activity.recent.map((item) => (
              <li
                key={item.id}
                className="flex items-center justify-between rounded-lg border border-border p-3 text-sm"
              >
                <span className="text-foreground">{item.name}</span>
                <time dateTime={item.created_at} className="font-mono text-xs text-muted-foreground">
                  {new Date(item.created_at).toLocaleString()}
                </time>
              </li>
            ))}
          </ul>
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
      {copied ? "Copied" : "Copy"}
    </Button>
  );
}
