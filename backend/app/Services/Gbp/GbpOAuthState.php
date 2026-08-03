<?php

namespace App\Services\Gbp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Google's redirect back to /gbp/callback carries no Sanctum bearer token —
 * the browser follows a redirect Google issued, not a request our frontend
 * controls, so there's no Authorization header to attach. The OAuth `state`
 * parameter is the standard place to carry which tenant started the flow,
 * but .claude/SECURITY.md #2 still applies: the callback must never trust a
 * client-suppliable value for tenant identity on its own.
 *
 * Two properties make this safe:
 *  - Tamper-proof: the state value handed to Google is Crypt::encryptString()
 *    of a random nonce, not the tenant_id itself. Only this server can have
 *    produced it.
 *  - Single-use + time-limited: the nonce -> tenant_id mapping lives in
 *    cache for 10 minutes and is deleted (Cache::pull, get+forget
 *    atomically) the moment it's consumed. A captured state value cannot be
 *    replayed to attribute a second, attacker-controlled connection to the
 *    same tenant.
 *
 * This layer exists ON TOP of, not instead of, GbpController's
 * ->stateless() call — Socialite's own state handling
 * (AbstractProvider::hasInvalidState()) unconditionally returns false (i.e.
 * "not invalid") when stateless, by design, since stateless mode is for
 * contexts with no session to store its state in. Nothing else validates
 * the round-tripped state value unless this class does it.
 */
class GbpOAuthState
{
    private const TTL_MINUTES = 10;

    public function generate(string $tenantId): string
    {
        $nonce = Str::random(40);

        Cache::put($this->cacheKey($nonce), $tenantId, now()->addMinutes(self::TTL_MINUTES));

        return Crypt::encryptString($nonce);
    }

    /**
     * Returns the tenant_id the state was generated for, or null if the
     * state is missing, forged, expired, or already used.
     */
    public function consume(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        try {
            $nonce = Crypt::decryptString($state);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }

        return Cache::pull($this->cacheKey($nonce));
    }

    private function cacheKey(string $nonce): string
    {
        return "gbp_oauth_state:{$nonce}";
    }
}
