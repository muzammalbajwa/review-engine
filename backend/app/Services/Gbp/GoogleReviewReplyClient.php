<?php

namespace App\Services\Gbp;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Wraps Google's My Business API v4 reviews.updateReply endpoint. Same
 * caveat as GoogleBusinessProfileClient/GoogleReviewsClient: no real Google
 * credentials exist yet, so this matches Google's published API shape but
 * has not been exercised against the live service.
 *
 * $locationId is used exactly as GoogleReviewsClient uses it (the raw
 * `location_id` stored on GbpConnection) so a review's full resource name is
 * built the same way here as it was read from there — see that class's own
 * caveat about the v1-vs-v4 resource-name shape; this reuses whatever value
 * is already stored rather than introducing a second interpretation of it.
 */
class GoogleReviewReplyClient
{
    /**
     * @throws GoogleReplyPolicyViolationException if Google refuses the
     *                                             reply content itself
     * @throws RequestException for any other
     *                          (transient/auth) failure
     */
    public function postReply(string $accessToken, string $locationId, string $googleReviewId, string $body): void
    {
        $response = Http::withToken($accessToken)
            ->put("https://mybusiness.googleapis.com/v4/{$locationId}/reviews/{$googleReviewId}/reply", [
                'comment' => $body,
            ]);

        // Google returns 400 for content that violates its reply policies
        // (e.g. off-topic, promotional, abusive) — distinct from 401/403
        // (auth) or 5xx (transient), which are left to throw() below so
        // they're retryable rather than treated as a permanent rejection.
        if ($response->status() === 400) {
            throw new GoogleReplyPolicyViolationException(
                $response->json('error.message') ?? 'Google rejected this reply.'
            );
        }

        $response->throw();
    }
}
