<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sending-access feature gate. Applied to exactly the endpoints that
 * create a NEW outbound review request (CSV import, both quick-add entry
 * points, the webhook API) — never to reading contacts/templates/campaign
 * history, which a blocked tenant keeps in full
 * (.claude/CLAUDE.md-adjacent decision doc: "can still log in, view
 * their dashboard, contacts, templates, and past campaign history").
 *
 * Fires for three distinct reasons that share the same
 * Tenant::sendingBlockedReason() gate (.claude/BILLING.md's "Auto-renew
 * toggle" section for the first two; the "add email verification"
 * decision doc for the third) — the response below reflects which one
 * actually happened rather than always saying "trial ended," since that
 * copy would be wrong for a tenant whose paid subscription stopped
 * renewing, or whose owner just hasn't verified their email yet.
 *
 * Reads CurrentTenant rather than $request->user()->tenant so this works
 * identically behind the authenticated 'tenant' middleware group AND
 * behind the public quick-add link's ResolveQuickAddTenant (which sets
 * CurrentTenant + the RLS session var from the {token} route segment,
 * with no Sanctum user at all) — same tenant-resolution contract every
 * other tenant-scoped read in this app relies on.
 *
 * Placed in the route middleware array AFTER 'tenant' /
 * 'quick-add.tenant' — it needs CurrentTenant already resolved. Neither
 * of those implements an interface in Laravel's hardcoded middleware
 * priority list, so (unlike SetTenantContext/CheckAbilities elsewhere in
 * this app) declared array order is reliable here; no
 * prependToPriorityList() call needed.
 */
class RequireSendingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = app(CurrentTenant::class)->id();

        // Tenant reads its own row under the normal (non-bypass)
        // tenant_isolation RLS policy — id = current_tenant_id, already
        // active by the time this middleware runs.
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;

        $reason = $tenant?->sendingBlockedReason();

        if ($reason !== null) {
            $message = match ($reason) {
                'trial_expired' => 'Your trial ended — subscribe to keep sending review requests.',
                'subscription_ended' => 'Your subscription has ended — resubscribe to keep sending review requests.',
                'email_unverified' => 'Verify your email to start sending review requests.',
            };

            return response()->json([
                'error' => $reason,
                'message' => $message,
                'fields' => null,
            ], 422);
        }

        return $next($request);
    }
}
