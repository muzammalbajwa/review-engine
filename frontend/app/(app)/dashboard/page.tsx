import Link from "next/link";
import { redirect } from "next/navigation";

import { AppShell } from "@/components/AppShell";
import { Button } from "@/components/ui/button";
import { apiFetch, type ApiResult } from "@/lib/api";
import { requireToken } from "@/lib/session";
import { getOnboardingStatus } from "../onboarding/actions";
import type { Paginated, Review } from "../reviews/actions";

type ReviewsResult = ApiResult<Paginated<Review>>;

type CampaignAnalytics = {
  sends: { total: number };
  clicks: { total: number; rate: number };
  reviews_landed: { total: number; rate: number };
};

// Only `.total` is read here — the individual contact fields aren't
// needed on the dashboard, so they're left untyped rather than duplicating
// a full Contact shape no screen defines yet.
type ContactsPage = { total: number };

/**
 * .claude/FRONTEND.md: "/dashboard — KPI strip + recent activity." The
 * real landing screen post-login — gates on onboarding completion first,
 * since an account that never finished the guided flow shouldn't land
 * here without being sent back to it.
 */
export default async function DashboardPage() {
  await requireToken();

  const status = await getOnboardingStatus();

  if (status === null || !status.completed) {
    redirect("/onboarding");
  }

  const [analyticsResult, reviewsResult, contactsResult] = await Promise.all([
    apiFetch<CampaignAnalytics>("/analytics/campaign"),
    apiFetch<Paginated<Review>>("/reviews"),
    apiFetch<ContactsPage>("/contacts"),
  ]);

  const contactsTotal = contactsResult.ok ? contactsResult.data.total : null;
  const sendsTotal = analyticsResult.ok ? analyticsResult.data.sends.total : null;

  // .claude's brief for this screen: a tenant with zero contacts and zero
  // campaigns gets a "get started" prompt, not a wall of empty KPI tiles.
  // Onboarding-incomplete tenants never reach this branch — they're
  // redirected above — so this covers the tenant who finished onboarding
  // but explicitly skipped both GBP and contacts.
  const isBrandNew = contactsTotal === 0 && sendsTotal === 0;

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-3xl">
          <h1 className="mb-6 text-lg font-semibold">Dashboard</h1>

          <IncompleteSetupBanner status={status} hidden={isBrandNew} />

          {isBrandNew ? (
            <GetStartedPrompt />
          ) : (
            <>
              <div
                data-tour="kpi-strip"
                className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5"
              >
                <StatTile
                  label="Contacts imported"
                  value={contactsTotal !== null ? String(contactsTotal) : "—"}
                />
                <StatTile
                  label="Requests sent"
                  value={sendsTotal !== null ? String(sendsTotal) : "—"}
                />
                {analyticsResult.ok && (
                  <StatTile
                    label="Click rate"
                    value={formatPercent(analyticsResult.data.clicks.rate)}
                    sublabel={pluralize(analyticsResult.data.clicks.total, "click")}
                  />
                )}
                {analyticsResult.ok && (
                  <StatTile
                    label="Reviews landed"
                    value={formatPercent(analyticsResult.data.reviews_landed.rate)}
                    sublabel={pluralize(analyticsResult.data.reviews_landed.total, "review")}
                    emphasis
                  />
                )}
                <AverageRatingTile reviewsResult={reviewsResult} />
              </div>

              <RecentActivity reviewsResult={reviewsResult} />
            </>
          )}
        </div>
      </main>
    </AppShell>
  );
}

function GetStartedPrompt() {
  return (
    <div
      data-tour="get-started"
      className="mb-8 flex flex-col items-start gap-3 rounded-lg border border-border bg-card p-6"
    >
      <p className="font-heading text-base font-semibold text-foreground">Get your first reviews flowing</p>
      <p className="text-sm text-muted-foreground">
        You haven&apos;t imported any customers yet, so there&apos;s nothing to show here. Import your
        past customers and review requests can start going out.
      </p>
      <Button render={<Link href="/contacts/import" />} nativeButton={false}>
        Import your customers
      </Button>
    </div>
  );
}

function RecentActivity({ reviewsResult }: { reviewsResult: ReviewsResult }) {
  return (
    <section>
      <h2 className="mb-3 text-sm font-medium text-foreground">Recent activity</h2>
      {!reviewsResult.ok ? (
        <p className="text-sm text-muted-foreground">Couldn&apos;t load recent activity right now.</p>
      ) : reviewsResult.data.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No reviews yet — they&apos;ll show up here as they come in.
        </p>
      ) : (
        <ul className="flex flex-col gap-3">
          {reviewsResult.data.data.slice(0, 5).map((review) => (
            <li
              key={review.id}
              className="flex items-center justify-between rounded-lg border border-border p-3 text-sm"
            >
              <span className="text-foreground">
                <span className="font-medium">{review.reviewer_name ?? "Anonymous"}</span>{" "}
                <span className="text-muted-foreground">
                  {review.needs_reply ? "left a review — needs a reply" : "left a review"}
                </span>
              </span>
              {review.review_created_at && (
                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                  {new Date(review.review_created_at).toLocaleDateString()}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function AverageRatingTile({ reviewsResult }: { reviewsResult: ReviewsResult }) {
  if (!reviewsResult.ok) {
    return <StatTile label="Average rating" value="—" />;
  }

  // Computed from the most recently synced page of reviews (real rows,
  // not a new backend aggregate) — accurate for the review volumes this
  // audience actually has; a tenant with 50+ reviews would need a
  // dedicated backend aggregate for full-history accuracy.
  const rated = reviewsResult.data.data.filter((r): r is Review & { rating: number } => r.rating !== null);

  if (rated.length === 0) {
    return <StatTile label="Average rating" value="—" sublabel="No ratings yet" />;
  }

  const average = rated.reduce((sum, r) => sum + r.rating, 0) / rated.length;

  return (
    <StatTile
      label="Average rating"
      value={`${average.toFixed(1)}★`}
      sublabel={pluralize(rated.length, "rating")}
    />
  );
}

function IncompleteSetupBanner({
  status,
  hidden,
}: {
  status: { gbp_status: string; contacts_count: number };
  hidden: boolean;
}) {
  const gbpMissing = status.gbp_status !== "connected";
  const contactsMissing = status.contacts_count === 0;

  if (hidden || (!gbpMissing && !contactsMissing)) {
    return null;
  }

  return (
    <div className="mb-8 flex flex-col gap-2 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm">
      <p className="font-medium text-foreground">Your setup isn&apos;t finished yet</p>
      {gbpMissing && (
        <p className="text-foreground">
          Google Business Profile isn&apos;t connected — reviews won&apos;t sync until you connect it.{" "}
          <Link
            href="/gbp/connect"
            data-tour="setup-gbp"
            className="font-medium underline-offset-4 hover:underline"
          >
            Connect now
          </Link>
        </p>
      )}
      {contactsMissing && (
        <p className="text-foreground">
          You haven&apos;t imported any customers yet — review requests can&apos;t go out until you do.{" "}
          <Link href="/contacts/import" className="font-medium underline-offset-4 hover:underline">
            Import contacts
          </Link>
        </p>
      )}
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
  value: string;
  sublabel?: string;
  emphasis?: boolean;
}) {
  return (
    <div className="rounded-lg border border-border p-5">
      <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
      <p className={`mt-2 text-2xl font-semibold tabular-nums ${emphasis ? "text-success" : "text-foreground"}`}>
        {value}
      </p>
      {sublabel && <p className="mt-1 text-xs text-muted-foreground">{sublabel}</p>}
    </div>
  );
}

function formatPercent(rate: number): string {
  return `${Math.round(rate * 100)}%`;
}

function pluralize(count: number, noun: string): string {
  return `${count} ${noun}${count === 1 ? "" : "s"}`;
}
