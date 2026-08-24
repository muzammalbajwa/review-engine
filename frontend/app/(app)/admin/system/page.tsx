import { AlertTriangle, CheckCircle2 } from "lucide-react";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

type SystemStatus = {
  heartbeat: { healthy: boolean; reason: string | null };
  failed_jobs: {
    window_hours: number;
    total: number;
    by_job: { job: string; count: number }[];
  };
};

/**
 * .claude/QUEUE.md: the worker-heartbeat alarm existed only as an email to
 * ops before this — same underlying check (WorkerHeartbeatChecker), now
 * also a UI. Unlike the admin-mode banner in the layout (purely
 * informational, so neutral/moss per .claude/DESIGN.md's color rule), a
 * down worker is a genuine active incident — that's exactly the case the
 * rule reserves destructive/red for.
 */
export default async function AdminSystemPage() {
  await requireToken();

  const result = await apiFetch<SystemStatus>("/admin/system");

  return (
    <main className="flex justify-center p-8">
      <div className="w-full max-w-2xl">
        <h1 className="mb-6 text-lg font-semibold">System</h1>

        {!result.ok ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
          >
            {result.status === 403
              ? "You don't have admin access to view this page."
              : result.status === 401
                ? "Your session has expired. Log in again to continue."
                : result.message}
          </p>
        ) : (
          <div className="flex flex-col gap-6">
            <HeartbeatCard heartbeat={result.data.heartbeat} />
            <FailedJobsCard failedJobs={result.data.failed_jobs} />
          </div>
        )}
      </div>
    </main>
  );
}

function HeartbeatCard({ heartbeat }: { heartbeat: SystemStatus["heartbeat"] }) {
  if (heartbeat.healthy) {
    return (
      <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-5">
        <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-success" aria-hidden="true" />
        <div>
          <p className="font-medium text-foreground">Queue worker healthy</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Horizon is running. Review requests, syncs, and replies are going out normally.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div role="alert" className="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-5">
      <AlertTriangle className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden="true" />
      <div>
        <p className="font-medium text-destructive">Queue worker down</p>
        <p className="mt-1 text-sm text-foreground">{heartbeat.reason}</p>
        <p className="mt-1 text-sm text-muted-foreground">
          Every tenant&apos;s review requests, syncs, and replies stop silently while this is down. Check
          Horizon (<code className="font-mono">php artisan horizon:status</code>) and restart the worker
          process.
        </p>
      </div>
    </div>
  );
}

function FailedJobsCard({ failedJobs }: { failedJobs: SystemStatus["failed_jobs"] }) {
  return (
    <div className="rounded-lg border border-border p-5">
      <div className="flex items-baseline justify-between">
        <h2 className="text-sm font-medium text-foreground">Failed jobs</h2>
        <span className="text-xs text-muted-foreground">last {failedJobs.window_hours}h</span>
      </div>

      <p className="mt-2 text-2xl font-semibold tabular-nums text-foreground">{failedJobs.total}</p>

      {failedJobs.by_job.length > 0 && (
        <ul className="mt-4 flex flex-col gap-2 border-t border-border pt-4">
          {failedJobs.by_job.map((row) => (
            <li key={row.job} className="flex items-center justify-between text-sm">
              <span className="font-mono text-xs text-foreground">{row.job}</span>
              <span className="tabular-nums text-muted-foreground">{row.count}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
