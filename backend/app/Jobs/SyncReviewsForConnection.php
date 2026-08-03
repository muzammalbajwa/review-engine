<?php

namespace App\Jobs;

use App\Models\GbpConnection;
use App\Models\Review;
use App\Services\Gbp\GbpConnectionRevokedException;
use App\Services\Gbp\GbpTokenRefresher;
use App\Services\Gbp\GoogleReviewsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * .claude/ROADMAP.md Phase 3: "GBP OAuth... review sync". One job per
 * connection (not one job for all tenants) so a slow/stuck sync for one
 * tenant never blocks or delays another's, and so retries/backoff apply
 * per-connection rather than re-attempting everyone on any single failure.
 */
class SyncReviewsForConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $gbpConnectionId)
    {
    }

    /**
     * Keyed by connection id: two overruns of the same connection's sync
     * (a slow API response plus the next scheduled tick) must never run
     * concurrently and race on the same rows.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->gbpConnectionId))->expireAfter(600)];
    }

    public function handle(GbpTokenRefresher $tokenRefresher, GoogleReviewsClient $reviewsClient): void
    {
        // withoutGlobalScopes() only removes the Eloquent-layer scope — RLS
        // still applies, and at this point in the job no tenant context has
        // been set at all. Without a bypass here, this lookup silently
        // depends on whatever app.current_tenant_id happens to be left over
        // from something else that ran earlier on this connection (a
        // previous job, another savepoint) and can return null for a
        // perfectly valid connection id whenever that stale value doesn't
        // match. Caught by this job's own two-tenant test, not assumed
        // safe. Bypass is scoped to exactly this one by-primary-key lookup
        // — the id itself came from the trusted dispatcher (the scheduled
        // command), never from request input.
        $connection = DB::transaction(function () {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            return GbpConnection::withoutGlobalScopes()->find($this->gbpConnectionId);
        });

        // Deleted since this job was queued, or already known bad from a
        // previous run — nothing to do either way.
        if ($connection === null || $connection->status !== 'connected') {
            return;
        }

        DB::transaction(function () use ($connection, $tokenRefresher, $reviewsClient) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $connection->tenant_id]);

            try {
                $connection = $tokenRefresher->refreshIfNeeded($connection);
            } catch (GbpConnectionRevokedException $e) {
                // GbpTokenRefresher already flipped status to 'revoked' and
                // saved it. Phase 3 Step 4 alerts the tenant from here;
                // for now, stop cleanly rather than retrying a connection
                // that will never succeed again without the tenant
                // reconnecting.
                Log::warning('GBP connection revoked during review sync', [
                    'gbp_connection_id' => $connection->id,
                    'tenant_id' => $connection->tenant_id,
                ]);

                return;
            }

            $reviews = $reviewsClient->fetchReviews($connection->oauth_token, $connection->location_id);

            foreach ($reviews as $reviewData) {
                // Deliberately not firstOrCreate(['tenant_id' => ..., ...],
                // [...]) — firstOrCreate()'s create path is mass assignment,
                // and tenant_id is excluded from Review::$fillable (same
                // reasoning as every other tenant model: it must never be
                // settable from arbitrary array input). That combination
                // silently drops tenant_id from the insert and trips RLS's
                // WITH CHECK — caught by this job's own tests, not assumed
                // safe. Existence check + explicit assignment instead,
                // matching the same pattern AuthController and
                // ContactController use.
                $exists = Review::query()
                    ->where('tenant_id', $connection->tenant_id)
                    ->where('google_review_id', $reviewData['google_review_id'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                $review = new Review([
                    'gbp_connection_id' => $connection->id,
                    'google_review_id' => $reviewData['google_review_id'],
                    'rating' => $reviewData['rating'],
                    'text' => $reviewData['text'],
                    'reviewer_name' => $reviewData['reviewer_name'],
                    'review_created_at' => $reviewData['review_created_at'],
                    'needs_reply' => true,
                    'synced_at' => now(),
                ]);
                $review->tenant_id = $connection->tenant_id;
                $review->save();
            }

            $connection->last_synced_at = now();
            $connection->save();
        });
    }
}
