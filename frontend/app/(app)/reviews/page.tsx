import { AppShell } from "@/components/AppShell";
import { ReviewsTour } from "@/components/ReviewsTour";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { TourStatus } from "@/lib/tours";
import type { GbpStatus } from "../gbp/actions";
import { ReviewsList } from "./ReviewsList";
import type { Paginated, Review } from "./actions";

/**
 * .claude/FRONTEND.md screen: reviews + replies. Server Component — reads
 * GET /reviews from the Laravel API only, then hands the list to the
 * client-side component for the interactive reply flow.
 */
export default async function ReviewsPage() {
  await requireToken();

  const [result, tourResult, gbpResult] = await Promise.all([
    apiFetch<Paginated<Review>>("/reviews"),
    apiFetch<TourStatus>("/tours/status"),
    apiFetch<GbpStatus>("/gbp/status"),
  ]);
  const alreadySeenTour = !tourResult.ok || tourResult.data.tours_seen.reviews_list === true;
  const gbpConnected = gbpResult.ok && gbpResult.data.status === "connected";

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-2xl">
          <div className="mb-6 flex items-center gap-1.5">
            <h1 data-tour="reviews-heading" className="text-lg font-semibold">
              Reviews
            </h1>
            <ReviewsTour alreadySeen={alreadySeenTour} gbpConnected={gbpConnected} />
          </div>

          <div data-tour="reviews-area">
            {!result.ok ? (
              <p
                role="alert"
                className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
              >
                {result.status === 401
                  ? "Your session has expired. Log in again to continue."
                  : result.message}
              </p>
            ) : result.data.data.length === 0 ? (
              <p className="text-sm text-muted-foreground">No reviews yet.</p>
            ) : (
              <ReviewsList initialReviews={result.data.data} />
            )}
          </div>
        </div>
      </main>
    </AppShell>
  );
}
