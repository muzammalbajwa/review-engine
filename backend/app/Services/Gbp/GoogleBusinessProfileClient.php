<?php

namespace App\Services\Gbp;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the real Google Business Profile REST APIs (Account
 * Management + Business Information — GBP's OAuth scope covers both). Kept
 * as its own class so it's the one seam Phase 3's tests replace: no real
 * Google credentials exist yet (GOOGLE_CLIENT_ID/SECRET are blank), so
 * anything that actually calls Google is unverified against the live API
 * and needs re-checking once real credentials are added — the endpoints and
 * field names here (accounts.list, locations.list, metadata.newReviewUri)
 * match Google's published API shape but have not been exercised against
 * the real service.
 */
class GoogleBusinessProfileClient
{
    /**
     * @throws \Illuminate\Http\Client\RequestException
     * @throws \RuntimeException if the account has no manageable location
     */
    public function fetchPrimaryLocation(string $accessToken): array
    {
        $accounts = Http::withToken($accessToken)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts')
            ->throw()
            ->json('accounts', []);

        $accountName = $accounts[0]['name'] ?? null;

        if ($accountName === null) {
            throw new \RuntimeException('Google account has no Business Profile accounts.');
        }

        $locations = Http::withToken($accessToken)
            ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations", [
                'readMask' => 'name,title,metadata',
            ])
            ->throw()
            ->json('locations', []);

        $location = $locations[0] ?? null;

        if ($location === null) {
            throw new \RuntimeException('Google Business Profile account has no locations.');
        }

        return [
            'location_id' => $location['name'],
            'review_link' => $location['metadata']['newReviewUri'] ?? null,
        ];
    }
}
