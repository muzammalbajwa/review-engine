import { Button } from "@/components/ui/button";
import { startGbpConnect, type GbpStatus } from "@/app/(app)/gbp/actions";

/**
 * Shared across /gbp/connect, the onboarding wizard's GBP step, and
 * /settings' Google Business Profile tab — one connection-status card,
 * not three built separately. Reuses the same `startGbpConnect` server
 * action everywhere.
 */
export function GbpConnectionCard({ status }: { status: GbpStatus }) {
  return (
    <div className="rounded-lg border border-border p-6">
      <div className="flex items-center gap-2">
        <span
          aria-hidden="true"
          className={`inline-block size-2.5 rounded-full ${
            status.status === "connected"
              ? "bg-success"
              : status.status === "revoked"
                ? "bg-destructive"
                : "bg-muted-foreground"
          }`}
        />
        <span className="text-sm font-medium">
          {status.status === "connected" && "Connected"}
          {status.status === "revoked" && "Connection revoked"}
          {status.status === "not_connected" && "Not connected"}
        </span>
      </div>

      {status.status === "connected" && (
        <div className="mt-3 flex flex-col gap-1 text-sm text-muted-foreground">
          {status.review_link && (
            <p>
              Review link:{" "}
              <a
                href={status.review_link}
                target="_blank"
                rel="noopener noreferrer"
                className="text-primary underline-offset-4 hover:underline"
              >
                {status.review_link}
              </a>
            </p>
          )}
          {status.last_synced_at && <p>Last synced {new Date(status.last_synced_at).toLocaleString()}</p>}
        </div>
      )}

      {status.status === "revoked" && (
        <p className="mt-3 text-sm text-destructive">
          Access to this Google account was revoked or expired. Reviews have stopped syncing and
          replies can&apos;t be posted until you reconnect.
        </p>
      )}

      {status.status === "not_connected" && (
        <p className="mt-3 text-sm text-muted-foreground">
          Connect your Google Business Profile to start syncing reviews and posting replies.
        </p>
      )}

      <form action={startGbpConnect} className="mt-5">
        {status.status === "connected" ? (
          <Button type="submit" variant="outline">
            Reconnect
          </Button>
        ) : (
          <Button type="submit">Connect Google Business Profile</Button>
        )}
      </form>
    </div>
  );
}
