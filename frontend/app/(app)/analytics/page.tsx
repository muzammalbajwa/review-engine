import { AppShell } from "@/components/AppShell";
import { AnalyticsTour } from "@/components/AnalyticsTour";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { TourStatus } from "@/lib/tours";

type CampaignAnalytics = {
  sends: { total: number; by_step: Record<"1" | "2" | "3", number> };
  clicks: { total: number; rate: number };
  reviews_landed: { total: number; rate: number };
};

const STEP_LABELS: Record<string, string> = {
  "1": "Initial request",
  "2": "Follow-up",
  "3": "Reactivation",
};

/**
 * .claude/FRONTEND.md screen 3: campaign dashboard. Server Component —
 * one call to the Laravel aggregation endpoint, no client-side
 * computation over rows.
 */
export default async function AnalyticsPage() {
  await requireToken();

  const [result, tourResult] = await Promise.all([
    apiFetch<CampaignAnalytics>("/analytics/campaign"),
    apiFetch<TourStatus>("/tours/status"),
  ]);
  const alreadySeenTour = !tourResult.ok || tourResult.data.tours_seen.analytics === true;
  const hasActivity = result.ok && result.data.sends.total > 0;

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-2xl">
          <div className="mb-6 flex items-center gap-1.5">
            <h1 data-tour="analytics-heading" className="text-lg font-semibold">
              Campaign performance
            </h1>
            <AnalyticsTour alreadySeen={alreadySeenTour} hasActivity={hasActivity} />
          </div>

          {!result.ok ? (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
            >
              {result.status === 401
                ? "Your session has expired. Log in again to continue."
                : result.message}
            </p>
          ) : result.data.sends.total === 0 ? (
            <p className="text-sm text-muted-foreground">
              No campaign activity yet. Once review requests start going out, sends, clicks, and reviews
              landed will show up here.
            </p>
          ) : (
            <Dashboard data={result.data} />
          )}
        </div>
      </main>
    </AppShell>
  );
}

function Dashboard({ data }: { data: CampaignAnalytics }) {
  return (
    <div className="flex flex-col gap-6">
      <div data-tour="analytics-stats" className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatTile label="Requests sent" value={data.sends.total} />
        <StatTile
          label="Click rate"
          value={formatPercent(data.clicks.rate)}
          sublabel={`${data.clicks.total} click${data.clicks.total === 1 ? "" : "s"}`}
        />
        <StatTile
          label="Reviews landed"
          value={formatPercent(data.reviews_landed.rate)}
          sublabel={`${data.reviews_landed.total} review${data.reviews_landed.total === 1 ? "" : "s"}`}
          emphasis="success"
        />
      </div>

      <div data-tour="analytics-by-step" className="rounded-lg border border-border p-5">
        <h2 className="text-sm font-medium text-foreground">Sends by step</h2>
        <div className="mt-4 flex flex-col gap-3">
          {(["1", "2", "3"] as const).map((step) => (
            <StepBar
              key={step}
              label={STEP_LABELS[step]}
              count={data.sends.by_step[step]}
              max={Math.max(1, data.sends.total)}
              tone={step}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

function StatTile({
  label,
  value,
  sublabel,
  emphasis,
}: {
  label: string;
  value: string | number;
  sublabel?: string;
  emphasis?: "success";
}) {
  return (
    <div className="rounded-lg border border-border p-5">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p
        className={`mt-2 text-2xl font-semibold tabular-nums ${
          emphasis === "success" ? "text-success" : "text-foreground"
        }`}
      >
        {value}
      </p>
      {sublabel && <p className="mt-1 text-xs text-muted-foreground">{sublabel}</p>}
    </div>
  );
}

const STEP_BAR_COLOR: Record<string, string> = {
  "1": "bg-chart-1",
  "2": "bg-chart-2",
  "3": "bg-chart-3",
};

function StepBar({
  label,
  count,
  max,
  tone,
}: {
  label: string;
  count: number;
  max: number;
  tone: "1" | "2" | "3";
}) {
  const widthPercent = Math.round((count / max) * 100);

  return (
    <div className="flex items-center gap-3" title={`${label}: ${count} sent`}>
      <span className="w-32 shrink-0 text-xs text-muted-foreground">{label}</span>
      <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
        <div
          className={`h-full rounded-full ${STEP_BAR_COLOR[tone]}`}
          style={{ width: `${widthPercent}%` }}
        />
      </div>
      <span className="w-8 shrink-0 text-right text-xs tabular-nums text-foreground">{count}</span>
    </div>
  );
}

function formatPercent(rate: number): string {
  return `${Math.round(rate * 100)}%`;
}
