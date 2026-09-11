<?php

namespace App\Services\Gbp;

use App\Models\GbpConnection;
use App\Models\User;
use App\Notifications\GbpConnectionRevoked;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

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
     * @throws RequestException for any other failure
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
                $this->markRevoked($connection);

                throw new GbpConnectionRevokedException($connection);
            }

            $response->throw();
        }

        $connection->oauth_token = $response->json('access_token');
        $connection->token_expires_at = now()->addSeconds($response->json('expires_in'));
        $connection->save();

        return $connection;
    }

    /**
     * .claude/QUEUE.md: the tenant needs to actually be told when their
     * connection dies, not just have it silently marked in the database.
     * Idempotent via revoked_alert_sent_at: safe to call this on a
     * connection that's already revoked and already alerted (e.g. a caller
     * that, unlike SyncReviewsForConnection/ReviewController's own
     * upfront `status !== 'connected'` guards, invokes refreshIfNeeded
     * again anyway) — status is re-written harmlessly, but the email never
     * goes out twice for the same revocation. Reset back to null on
     * reconnect (GbpController::callback()) so a *future* revocation
     * alerts again.
     */
    private function markRevoked(GbpConnection $connection): void
    {
        $alreadyAlerted = $connection->revoked_alert_sent_at !== null;

        $connection->status = 'revoked';

        if (! $alreadyAlerted) {
            $connection->revoked_alert_sent_at = now();
        }

        $connection->save();

        if ($alreadyAlerted) {
            return;
        }

        // QA-audit fix (Finding 1, CRITICAL): ad-hoc mail route, never
        // $owner->notify(...) — this runs from a queued job
        // (SyncReviewsForConnection), so $owner->notify(...) would hand
        // Laravel's queued-notification handling a real Eloquent User to
        // rehydrate on whatever worker eventually processes THIS
        // notification's own job too. With no RLS tenant context set on
        // that worker, the re-fetch returns zero rows and Laravel
        // silently discards the job as if it had succeeded — see
        // App\Notifications\VerifyEmailAddress's docblock for the full
        // mechanism. GbpConnectionRevoked::toMail() never reads
        // $notifiable, so no further change is needed there.
        User::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('role', 'owner')
            ->get()
            ->each(fn (User $owner) => Notification::route('mail', $owner->email)->notify(new GbpConnectionRevoked));
    }
}
