<?php

namespace App\Services\Gbp;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Wraps Google's My Business API v4 reviews.list endpoint. Same caveat as
 * GoogleBusinessProfileClient: no real Google credentials exist yet, so
 * this is built to match Google's published API shape but has not been
 * exercised against the live service.
 */
class GoogleReviewsClient
{
    private const RATING_MAP = [
        'ONE' => 1,
        'TWO' => 2,
        'THREE' => 3,
        'FOUR' => 4,
        'FIVE' => 5,
    ];

    // Safety cap on pagination — a single sync tick should never be able to
    // loop indefinitely against a misbehaving or malicious response.
    private const MAX_PAGES = 20;

    /**
     * @return array<int, array{google_review_id: string, rating: ?int, text: ?string, reviewer_name: ?string, review_created_at: ?string}>
     *
     * @throws RequestException
     */
    public function fetchReviews(string $accessToken, string $locationId): array
    {
        $reviews = [];
        $pageToken = null;
        $page = 0;

        do {
            $page++;

            $response = Http::withToken($accessToken)
                ->get("https://mybusiness.googleapis.com/v4/{$locationId}/reviews", array_filter([
                    'pageToken' => $pageToken,
                ]))
                ->throw();

            foreach ($response->json('reviews', []) as $raw) {
                $reviews[] = $this->normalize($raw);
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken !== null && $page < self::MAX_PAGES);

        return $reviews;
    }

    private function normalize(array $raw): array
    {
        return [
            'google_review_id' => $raw['reviewId'],
            'rating' => self::RATING_MAP[$raw['starRating'] ?? ''] ?? null,
            'text' => $raw['comment'] ?? null,
            'reviewer_name' => $raw['reviewer']['displayName'] ?? null,
            'review_created_at' => $raw['createTime'] ?? null,
        ];
    }
}
