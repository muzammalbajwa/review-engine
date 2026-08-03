<?php

namespace App\Services\Gbp;

use App\Models\GbpConnection;
use Illuminate\Support\Facades\Http;

/**
 * GBP access tokens are short-lived (~1 hour); the refresh_token is what
 * makes a connection actually last. Google's OAuth2 token endpoint itself
 * is stable, documented, unversioned API surface (unlike the Business
 * Profile APIs elsewhere in Phase 3) — this part is confidently correct
 * even without real credentials to test end-to-end against.
 */
class GbpTokenRefresher
{
    // Refresh a bit before actual expiry so a slow request never straddles
    // the boundary and gets rejected mid-call.
    private const EXPIRY_BUFFER_SECONDS = 300;

    /**
     * @throws GbpConnectionRevokedException if Google reports the refresh
     *                                       token itself is no longer valid
     * @throws \Illuminate\Http\Client\RequestException for any other failure
     */
    public function refreshIfNeeded(GbpConnection $connection): GbpConnection
    {
        if ($connection->token_expires_at->subSeconds(self::EXPIRY_BUFFER_SECONDS)->isFuture()) {
            return $connection;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            // Google's documented response for a dead refresh token (user
            // revoked access in their Google Account, the grant expired, or
            // the account was deleted/suspended) is 400 + error=invalid_grant.
            if ($response->json('error') === 'invalid_grant') {
                $connection->status = 'revoked';
                $connection->save();

                throw new GbpConnectionRevokedException($connection);
            }

            $response->throw();
        }

        $connection->oauth_token = $response->json('access_token');
        $connection->token_expires_at = now()->addSeconds($response->json('expires_in'));
        $connection->save();

        return $connection;
    }
}
