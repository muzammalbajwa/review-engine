<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GbpConnection;
use App\Services\Gbp\GbpOAuthState;
use App\Services\Gbp\GoogleBusinessProfileClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * .claude/API.md: "GBP: GET /gbp/connect (OAuth start), GET /gbp/callback".
 */
class GbpController extends Controller
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/business.manage',
        'openid',
        'email',
    ];

    public function __construct(
        private readonly GbpOAuthState $oauthState,
        private readonly GoogleBusinessProfileClient $gbpClient,
    ) {}

    /**
     * .claude/FRONTEND.md screen: connection status for a settings page
     * (connected / not_connected / revoked). Read-only — no behavior
     * change to the connect/callback flow below. Deliberately returns an
     * explicit allowlist of fields, never the model directly: GbpConnection
     * has no $hidden for oauth_token/refresh_token (its `encrypted` casts
     * only protect the column at rest, not the API response — a naive
     * ['data' => $connection] would decrypt and serialize both tokens
     * straight into the JSON body).
     */
    public function status(Request $request): JsonResponse
    {
        $connection = GbpConnection::query()->first();

        if ($connection === null) {
            return response()->json(['data' => [
                'status' => 'not_connected',
                'location_id' => null,
                'review_link' => null,
                'last_synced_at' => null,
            ]]);
        }

        return response()->json(['data' => [
            'status' => $connection->status,
            'location_id' => $connection->location_id,
            'review_link' => $connection->review_link,
            'last_synced_at' => $connection->last_synced_at,
        ]]);
    }

    /**
     * Authenticated (behind 'tenant'): this is the one place tenant_id is
     * ever read from the request lifecycle for this flow — everything after
     * this runs through the signed state parameter instead, since
     * /gbp/callback has no session/bearer-token context at all.
     */
    public function connect(Request $request): JsonResponse
    {
        // Socialite happily builds a URL with a blank client_id; Google then
        // shows the user its own raw error page instead of anything we control.
        if (! $this->googleOAuthConfigured() || $this->googleRejectsOAuthClient()) {
            Log::error('GBP connect attempted but Google OAuth credentials are missing or rejected by Google');

            return response()->json([
                'error' => 'gbp_not_configured',
                'message' => 'Connecting Google Business Profile isn\'t available right now. Please try again later or contact support.',
                'fields' => null,
            ], 503);
        }

        $state = $this->oauthState->generate($request->user()->tenant_id);

        $redirectUrl = Socialite::driver('google')
            ->stateless()
            ->scopes(self::SCOPES)
            ->with([
                'access_type' => 'offline',
                // Forces Google to always return a refresh_token, even if
                // this Google account already granted consent previously —
                // without it, a re-connect after a revoke can silently come
                // back with no refresh_token at all.
                'prompt' => 'consent',
                'state' => $state,
            ])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['data' => ['redirect_url' => $redirectUrl]]);
    }

    private function googleOAuthConfigured(): bool
    {
        $clientId = trim((string) config('services.google.client_id'));
        $clientSecret = trim((string) config('services.google.client_secret'));
        $redirect = trim((string) config('services.google.redirect'));

        return $clientId !== ''
            && $clientSecret !== ''
            && filter_var($redirect, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * A wrong client ID or secret only surfaces on Google's own consent
     * page ("Error 401: invalid_client"). Exchanging a dummy code at the
     * token endpoint tells us first: Google answers invalid_client for bad
     * credentials and invalid_grant (the dummy code) for good ones. It can't
     * catch a redirect URI missing from the Google console; that is only
     * checked during the real consent flow.
     */
    private function googleRejectsOAuthClient(): bool
    {
        $clientId = (string) config('services.google.client_id');
        $clientSecret = (string) config('services.google.client_secret');
        $cacheKey = 'gbp:oauth-client-check:'.hash('sha256', $clientId."\0".$clientSecret);

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached === 'rejected';
        }

        try {
            $response = Http::asForm()->connectTimeout(3)->timeout(5)->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => 'reviewengine-credential-check',
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.google.redirect'),
            ]);
        } catch (ConnectionException $e) {
            // Can't tell either way; let the user continue to Google as before.
            Log::warning('Could not reach Google to check OAuth client credentials', ['error' => $e->getMessage()]);

            return false;
        }

        $rejected = $response->json('error') === 'invalid_client';

        if ($rejected) {
            Log::error('Google rejected the configured OAuth client', ['error_description' => $response->json('error_description')]);
        }

        Cache::put($cacheKey, $rejected ? 'rejected' : 'accepted', now()->addMinutes(10));

        return $rejected;
    }

    /**
     * Public route — no 'tenant' middleware, no Sanctum auth. Google's
     * redirect back here is the browser following a Location header Google
     * issued; it carries no Authorization header for us to authenticate
     * with. Tenant identity comes ONLY from GbpOAuthState::consume(), never
     * from any other part of this request (.claude/SECURITY.md #2).
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');

        $tenantId = $this->oauthState->consume($request->query('state'));

        if ($tenantId === null) {
            return redirect()->away("{$frontendUrl}/gbp/connect?error=invalid_state");
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning('GBP OAuth code exchange failed', ['error' => $e->getMessage()]);

            return redirect()->away("{$frontendUrl}/gbp/connect?error=oauth_failed");
        }

        if ($googleUser->refreshToken === null) {
            // Can happen if Google decides not to honor prompt=consent for
            // some accounts. Without a refresh token the connection is
            // useless past the ~1 hour access token lifetime.
            return redirect()->away("{$frontendUrl}/gbp/connect?error=no_refresh_token");
        }

        try {
            $location = $this->gbpClient->fetchPrimaryLocation($googleUser->token);
        } catch (\Throwable $e) {
            Log::warning('GBP location discovery failed', ['error' => $e->getMessage()]);

            return redirect()->away("{$frontendUrl}/gbp/connect?error=no_location_found");
        }

        DB::transaction(function () use ($tenantId, $googleUser, $location) {
            // Narrow RLS bypass, same pattern as every other pre-tenant-
            // context write (AuthController::register(), SetTenantContext):
            // set immediately before the write that needs it.
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

            $connection = GbpConnection::query()->firstOrNew(['tenant_id' => $tenantId]);
            $connection->tenant_id = $tenantId;
            $connection->oauth_token = $googleUser->token;
            $connection->refresh_token = $googleUser->refreshToken;
            $connection->token_expires_at = now()->addSeconds($googleUser->expiresIn ?? 3600);
            $connection->location_id = $location['location_id'];
            $connection->review_link = $location['review_link'];
            $connection->status = 'connected';
            // .claude/QUEUE.md: a fresh reconnect clears the previous
            // revocation's alert marker (GbpTokenRefresher::markRevoked())
            // so a *future* revocation sends its own alert instead of
            // staying permanently silenced by the last one.
            $connection->revoked_alert_sent_at = null;
            $connection->save();
        });

        return redirect()->away("{$frontendUrl}/gbp/connected");
    }
}
