<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GbpConnection;
use App\Services\Gbp\GbpOAuthState;
use App\Services\Gbp\GoogleBusinessProfileClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ) {
    }

    /**
     * Authenticated (behind 'tenant'): this is the one place tenant_id is
     * ever read from the request lifecycle for this flow — everything after
     * this runs through the signed state parameter instead, since
     * /gbp/callback has no session/bearer-token context at all.
     */
    public function connect(Request $request): JsonResponse
    {
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
            $connection->save();
        });

        return redirect()->away("{$frontendUrl}/gbp/connected");
    }
}
