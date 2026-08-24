<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The team-permissions gate (contacts/templates/reviews/analytics —
 * separate from the cross-tenant admin system, and separate from billing/
 * team management which are never grantable at all, see
 * EnsureTenantOwner). Applied per-resource route group in routes/api.php,
 * e.g. ->middleware('permission:templates').
 *
 * Enforced here, not just hidden in the frontend nav
 * (SidebarShell.tsx filters NAV_ITEMS by the same permission set) — a
 * member's direct API call to an endpoint they don't have access to must
 * be rejected server-side regardless of what the UI shows, same
 * "authorization is never just a UI concern" rule every other gate in
 * this app follows (RequireSendingAccess, TenantPolicy).
 *
 * Reads $request->user()->hasPermission() fresh on every request — no
 * caching anywhere in this chain, so a permission an owner just revoked
 * takes effect on the member's very next request, not next login.
 *
 * Placed after 'tenant' in every route group that uses it — needs
 * auth:sanctum to have already resolved $request->user().
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        if (! $request->user()->hasPermission($resource)) {
            return response()->json([
                'error' => 'permission_denied',
                'message' => 'You don\'t have access to this section. Ask the account owner to grant it.',
                'fields' => null,
            ], 403);
        }

        return $next($request);
    }
}
