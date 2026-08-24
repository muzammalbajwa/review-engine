<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unauthenticated counterpart to SetTenantContext — resolves tenant
 * from the {token} route segment of the public quick-add link
 * (reviewengine.com/quick/{token}) instead of a Sanctum bearer token.
 * Same two-layer activation (CurrentTenant for TenantScope, the Postgres
 * session variable for RLS), same narrow set-immediately-before/
 * clear-immediately-after bypass pattern, same whole-request transaction
 * wrapping so `set_config(..., true)` (local to the transaction) stays
 * active through the controller action.
 *
 * A missing/invalid token aborts with a clean 404 — never reveals
 * whether a guessed token is "close" to a real one.
 */
class ResolveQuickAddTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

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
            // Same defensive clear as SetTenantContext — never leave a
            // stale tenant context for whatever runs next on this worker.
            app(CurrentTenant::class)->clear();
        }
    }

    /**
     * Narrow, single-row RLS bypass — same app.bypass_tenant_scope flag
     * SetTenantContext uses for its own users-table login lookup, now
     * also granted on `tenants` (2026_08_04_120001 migration's
     * tenant_isolation_quick_add_lookup policy) for exactly this query.
     */
    private function resolveTenantIdForToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'true', true)");

        $tenantId = DB::table('tenants')->where('quick_add_token', $token)->value('id');

        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'false', true)");

        return $tenantId;
    }
}
