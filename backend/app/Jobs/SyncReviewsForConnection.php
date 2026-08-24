<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\GbpConnection;
use App\Models\Message;
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

    public function __construct(public readonly int $gbpConnectionId) {}

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

                $this->attemptMatchReviewToContact($review, $connection->tenant_id);
            }

            $connection->last_synced_at = now();
            $connection->save();
        });
    }

    /**
     * Cross-references a newly-synced review back to whichever contact it
     * most plausibly came from, so SendReviewRequest's own suppression
     * check (Message.reviewed_at — see its docblock, ".claude/QUEUE.md:
     * the check lives in the job") stops a follow-up for someone who
     * already left a review even if they never clicked our tracked link
     * to get there (e.g. searched the business on Google directly).
     *
     * BE HONEST ABOUT WHAT THIS ACTUALLY IS: Google's review payload gives
     * us a display name and a timestamp, never an email, phone, or any
     * customer id — there is no clean join key. This is a best-effort
     * heuristic, not a guarantee, matching on:
     *   1. Timing: the review must have been left after the message was
     *      sent (a customer can't review before being asked) and within
     *      REVIEW_MATCH_WINDOW_DAYS after — a match against something
     *      sent months ago is far more likely coincidental than causal.
     *   2. Name: reviewer_name and the contact's own name share the same
     *      first token exactly, and either the last tokens match in full
     *      or one is a single-letter initial of the other (Google
     *      commonly displays "Priya S." rather than a full surname) — see
     *      namesLikelyMatch() for the exact rule.
     *
     * Known false negatives (a real match we'll miss, and just keep
     * following up on someone who actually already reviewed): a review
     * left under a nickname, a spouse's/family member's Google account, a
     * business account name unrelated to the customer's own name, an
     * anonymized "A Google User" review, or a surname-only initial on
     * *our* side instead of Google's (this only recognizes Google's own
     * "First L." abbreviation, not the reverse).
     *
     * Known false positives (a wrong match, which actively suppresses a
     * real customer's legitimate follow-up): two different contacts who
     * happen to share the same first+last name, both messaged inside the
     * same window, with only one of them actually being the reviewer.
     * Deliberately NOT guessed at — see the ambiguous-match branch below.
     *
     * When more than one contact plausibly matches, this does *nothing*
     * rather than picking one — a wrong suppression is worse than a
     * missed one: .claude/COMPLIANCE.md's "requests go to ALL customers
     * equally" is undermined by an incorrect automatic match just as much
     * as by intentional gating, even though this isn't gating in the
     * regulatory sense (it changes *whether* to follow up, never *which*
     * link anyone gets).
     */
    private const REVIEW_MATCH_WINDOW_DAYS = 180;

    private function attemptMatchReviewToContact(Review $review, string $tenantId): void
    {
        $windowStart = $review->review_created_at->copy()->subDays(self::REVIEW_MATCH_WINDOW_DAYS);

        // Every contact with at least one already-sent, not-yet-resolved
        // message inside the matching window is a candidate — a contact
        // can have several such messages (step 1/2/3), we only need to
        // know the contact themselves is plausible before checking names.
        $candidates = Contact::query()
            ->whereHas('messages', function ($query) use ($review, $windowStart) {
                $query->whereNotNull('sent_at')
                    ->whereNull('reviewed_at')
                    ->where('sent_at', '<=', $review->review_created_at)
                    ->where('sent_at', '>=', $windowStart);
            })
            ->get()
            ->filter(fn (Contact $contact) => $this->namesLikelyMatch($contact->name, $review->reviewer_name));

        if ($candidates->count() !== 1) {
            if ($candidates->count() > 1) {
                Log::info('Review-to-contact match skipped: ambiguous', [
                    'tenant_id' => $tenantId,
                    'google_review_id' => $review->google_review_id,
                    'candidate_contact_ids' => $candidates->pluck('id')->all(),
                ]);
            }

            return;
        }

        $contact = $candidates->first();

        // The specific message stamped is the most recently sent one
        // still awaiting resolution — the one this review is most
        // plausibly a response to.
        $message = $contact->messages()
            ->whereNotNull('sent_at')
            ->whereNull('reviewed_at')
            ->where('sent_at', '<=', $review->review_created_at)
            ->where('sent_at', '>=', $windowStart)
            ->latest('sent_at')
            ->first();

        if ($message === null) {
            return;
        }

        $message->reviewed_at = $review->review_created_at;
        $message->save();

        Log::info('Review matched to contact', [
            'tenant_id' => $tenantId,
            'google_review_id' => $review->google_review_id,
            'contact_id' => $contact->id,
            'message_id' => $message->id,
        ]);
    }

    /**
     * Explainable, not clever: a raw similarity score (Levenshtein,
     * similar_text%) would be harder to reason about and harder to tune
     * without silently drifting the false-positive/false-negative
     * tradeoff described above. This is a fixed rule instead.
     */
    private function namesLikelyMatch(?string $contactName, ?string $reviewerName): bool
    {
        if ($contactName === null || $reviewerName === null) {
            return false;
        }

        $normalize = fn (string $value) => array_values(array_filter(
            explode(' ', trim(preg_replace('/[^a-z\s]/', '', strtolower($value))))
        ));

        $contactTokens = $normalize($contactName);
        $reviewerTokens = $normalize($reviewerName);

        if ($contactTokens === [] || $reviewerTokens === []) {
            return false;
        }

        // First name is the anchor — must match exactly, normalized.
        if ($contactTokens[0] !== $reviewerTokens[0]) {
            return false;
        }

        // Either side being a single token (a contact entered with only a
        // first name, or a Google account showing only a first name) —
        // the first-name match above is the whole check.
        if (count($contactTokens) === 1 || count($reviewerTokens) === 1) {
            return true;
        }

        $contactLast = end($contactTokens);
        $reviewerLast = end($reviewerTokens);

        return $contactLast === $reviewerLast
            || (strlen($reviewerLast) === 1 && $reviewerLast[0] === $contactLast[0])
            || (strlen($contactLast) === 1 && $contactLast[0] === $reviewerLast[0]);
    }
}
