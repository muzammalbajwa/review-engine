<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activates both isolation layers for the request (.claude/SECURITY.md #2):
 * Layer A (CurrentTenant, read by TenantScope) and Layer B (the Postgres
 * session variable RLS policies read).
 *
 * Must run BEFORE auth:sanctum. auth:sanctum's own guard has to read the
 * `users` row behind the presented token to authenticate at all, and
 * `users` has FORCE ROW LEVEL SECURITY — without a tenant context already
 * active on the connection, that read returns nothing and every token
 * fails to authenticate. Resolving tenant from $request->user() (the
 * previous approach, which ran this middleware after auth:sanctum) is a
 * circular dependency: it needs auth:sanctum to have already succeeded.
 *
 * Tenant is resolved from the bearer token's own row only — never a
 * header, query param, or body field the caller could forge. The lookup
 * is scoped to exactly one row (the token's own tokenable_id), the same
 * narrow set-immediately-before/clear-immediately-after bypass pattern
 * AuthController uses for login/register (.claude/SECURITY.md #2) — it can
 * only ever reveal the tenant of the user who already possesses this one
 * secret token, never any other tenant's data. Once this middleware sets
 * the correct tenant context, auth:sanctum's own read of `users` succeeds
 * through the normal, non-bypass tenant_isolation policy — no new RLS
 * policy or exception is introduced.
 *
 * If no bearer token is present, or it doesn't resolve to a user, this
 * middleware does nothing and lets auth:sanctum fail normally (401).
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken === null) {
            return $next($request);
        }

        try {
            return DB::transaction(function () use ($request, $next, $bearerToken) {
                $tenantId = $this->resolveTenantIdForToken($bearerToken);

                if ($tenantId !== null) {
                    // set_config(), not "SET LOCAL ... = ?" — Postgres's
                    // SET/SET LOCAL is a utility statement and rejects bind
                    // parameters outright. true = local to this transaction.
                    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

                    app(CurrentTenant::class)->set($tenantId);
                }

                return $next($request);
            });
        } finally {
            // Defensive: under a persistent worker (Octane, or a Horizon
            // queue worker process handling many jobs) this singleton would
            // otherwise survive across units of work — never leave a stale
            // tenant context sitting around for whatever runs next.
            app(CurrentTenant::class)->clear();
        }
    }

    /**
     * Narrow, single-row RLS bypass — mirrors AuthController's login/register
     * pattern. Set immediately before the one query that needs it, cleared
     * immediately after, never left active for anything else in the request.
     */
    private function resolveTenantIdForToken(string $bearerToken): ?string
    {
        $accessToken = PersonalAccessToken::findToken($bearerToken);

        if ($accessToken === null) {
            return null;
        }

        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'true', true)");

        $tenantId = DB::table('users')->where('id', $accessToken->tokenable_id)->value('tenant_id');

        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'false', true)");

        return $tenantId;
    }
}
