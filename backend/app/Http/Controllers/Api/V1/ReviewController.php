<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Ai\ClaudeReplyDrafter;
use App\Services\Gbp\GbpConnectionRevokedException;
use App\Services\Gbp\GbpTokenRefresher;
use App\Services\Gbp\GoogleReplyPolicyViolationException;
use App\Services\Gbp\GoogleReviewReplyClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * .claude/API.md: "Reviews: GET /reviews, POST /reviews/{id}/reply".
 * .claude/ROADMAP.md Phase 3 Step 3: "Claude-generated replies... Surface
 * PolicyViolation on rejected replies".
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly GbpTokenRefresher $tokenRefresher,
        private readonly ClaudeReplyDrafter $replyDrafter,
        private readonly GoogleReviewReplyClient $replyClient,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reviews = Review::query()
            ->when($request->boolean('needs_reply'), fn ($query) => $query->where('needs_reply', true))
            ->orderByDesc('review_created_at')
            ->paginate(50);

        return response()->json(['data' => $reviews]);
    }

    /**
     * Implicit route-model binding: {review} resolves through Review's own
     * global scope (BelongsToTenant/TenantScope), so a review belonging to
     * another tenant 404s here exactly like any other tenant-owned
     * resource — no separate Policy needed (same convention as
     * ContactController).
     */
    public function reply(Request $request, Review $review): JsonResponse
    {
        $connection = $review->gbpConnection;

        // Already known revoked from a previous sync/reply attempt (Phase 3
        // Step 4) — surface it immediately rather than attempting a refresh
        // that can only fail, same guard SyncReviewsForConnection uses.
        if ($connection->status !== 'connected') {
            return response()->json([
                'error' => 'gbp_connection_revoked',
                'message' => 'The Google Business Profile connection for this review has been revoked. Reconnect your Google account to reply.',
                'fields' => null,
            ], 409);
        }

        try {
            $connection = $this->tokenRefresher->refreshIfNeeded($connection);
        } catch (GbpConnectionRevokedException $e) {
            Log::warning('Reply attempted against a revoked GBP connection', [
                'review_id' => $review->id,
                'gbp_connection_id' => $connection->id,
            ]);

            return response()->json([
                'error' => 'gbp_connection_revoked',
                'message' => 'The Google Business Profile connection for this review has been revoked. Reconnect your Google account to reply.',
                'fields' => null,
            ], 409);
        }

        try {
            $body = $this->replyDrafter->draft($review);
        } catch (RequestException $e) {
            Log::error('Claude reply drafting failed', ['review_id' => $review->id, 'error' => $e->getMessage()]);

            return response()->json([
                'error' => 'ai_unavailable',
                'message' => 'Could not draft a reply right now. Try again shortly.',
                'fields' => null,
            ], 502);
        }

        $reply = $review->reply()->first() ?? $review->reply()->make();
        $reply->body = $body;

        try {
            $this->replyClient->postReply($connection->oauth_token, $connection->location_id, $review->google_review_id, $body);
        } catch (GoogleReplyPolicyViolationException $e) {
            // Never hide this (.claude/COMPLIANCE.md) — store exactly what
            // Google said and why, and leave needs_reply true so it keeps
            // surfacing as unresolved rather than silently dropping it.
            $reply->policy_violation = true;
            $reply->policy_violation_reason = $e->reason;
            $reply->posted_at = null;
            $reply->save();

            return response()->json([
                'error' => 'policy_violation',
                'message' => $e->reason,
                'fields' => null,
                'data' => ['reply' => $reply],
            ], 422);
        } catch (RequestException $e) {
            Log::error('Posting reply to Google failed', ['review_id' => $review->id, 'error' => $e->getMessage()]);

            return response()->json([
                'error' => 'gbp_unavailable',
                'message' => 'Google is temporarily unavailable. Try again shortly.',
                'fields' => null,
            ], 502);
        }

        $reply->policy_violation = false;
        $reply->policy_violation_reason = null;
        $reply->posted_at = now();
        $reply->save();

        $review->needs_reply = false;
        $review->save();

        return response()->json(['data' => $reply], 201);
    }
}
