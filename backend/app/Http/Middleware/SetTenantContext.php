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
 * is scoped to exactly one row (the token's own tenant_id column), the
 * same narrow set-immediately-before/clear-immediately-after bypass
 * pattern AuthController uses for login/register (.claude/SECURITY.md #2)
 * — it can only ever reveal the tenant of whoever already possesses this
 * one secret token, never any other tenant's data. Once this middleware
 * sets the correct tenant context, auth:sanctum's own read of `users`
 * succeeds through the normal, non-bypass tenant_isolation policy — no
 * new RLS policy or exception is introduced there.
 *
 * personal_access_tokens carries its own tenant_id column and RLS policy
 * (2026_08_06_133207_add_tenant_id_and_rls_to_personal_access_tokens_table.php)
 * — finding the token row at all, before any tenant is known, is exactly
 * the same bootstrapping problem `users` already had, solved the same way:
 * a narrow tenant_isolation_auth_lookup policy keyed on
 * app.bypass_tenant_scope, active only for the one lookup below.
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
     * pattern. Set immediately before the one query that needs it — finding
     * the bearer token by its hash, before any tenant is known at all —
     * cleared immediately after, never left active for anything else in the
     * request.
     *
     * Reads tenant_id directly off the token's own row (now that the column
     * exists) rather than a second lookup against `users` keyed by
     * tokenable_id — the token row itself is the authoritative source, not
     * an inference through its owner.
     */
    private function resolveTenantIdForToken(string $bearerToken): ?string
    {
        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'true', true)");

        $accessToken = PersonalAccessToken::findToken($bearerToken);

        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'false', true)");

        return $accessToken?->tenant_id;
    }
}
