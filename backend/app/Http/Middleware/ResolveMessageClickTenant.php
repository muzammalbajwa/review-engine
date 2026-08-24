<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unauthenticated counterpart to SetTenantContext/ResolveQuickAddTenant
 * — resolves tenant from the {token} segment of the public click-tracking
 * link (GET /api/v1/click/{token}) instead of a Sanctum bearer token or
 * the quick-add token. Same two-layer activation (CurrentTenant for
 * TenantScope, the Postgres session variable for RLS), same narrow
 * set-immediately-before/clear-immediately-after bypass pattern, same
 * whole-request transaction wrapping so `set_config(..., true)` (local to
 * the transaction) stays active through the controller action.
 *
 * A missing/malformed/invalid token aborts with a clean 404 — never
 * reveals whether a guessed token is "close" to a real one, same
 * discipline as ResolveQuickAddTenant.
 */
class ResolveMessageClickTenant
{
    /**
     * Str::random(48)'s exact output charset (base64 with /, +, = stripped
     * — see Illuminate\Support\Str::random()) — alphanumeric only, always
     * exactly 48 characters. Checked before the token ever reaches a query:
     * a cheap, fast rejection of anything malformed (wrong length, URL-
     * encoded junk, SQL-ish characters) without spending a DB round trip
     * on input that could never match a real token regardless.
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{48}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        if (! preg_match(self::TOKEN_PATTERN, $token)) {
            abort(404);
        }

        try {
            return DB::transaction(function () use ($request, $next, $token) {
                $tenantId = $this->resolveTenantIdForToken($token);

                if ($tenantId === null) {
                    abort(404);
                }

                DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
                app(CurrentTenant::class)->set($tenantId);

                return $next($request);
            });
        } finally {
            // Same defensive clear as SetTenantContext/ResolveQuickAddTenant
            // — never leave a stale tenant context for whatever runs next
            // on this worker.
            app(CurrentTenant::class)->clear();
        }
    }

    /**
     * Narrow, single-row RLS bypass — mirrors ResolveQuickAddTenant exactly,
     * now against messages.click_token
     * (2026_08_06_155437_add_click_lookup_policy_to_messages_table.php's
     * tenant_isolation_click_lookup policy) instead of tenants.quick_add_token.
     */
    private function resolveTenantIdForToken(string $token): ?string
    {
        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'true', true)");

        $tenantId = DB::table('messages')->where('click_token', $token)->value('tenant_id');

        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'false', true)");

        return $tenantId;
    }
}
