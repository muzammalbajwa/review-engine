<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .claude/API.md: "Tenant: GET/PATCH /tenant (self)". Documented since the
 * API contract was first written but never built — /settings' business
 * profile section is the first thing that needed it. Self-service only:
 * always $request->user()->tenant, never a client-supplied id, so this
 * needs no separate Policy (same reasoning as SenderIdentityController —
 * the tenant_isolation RLS policy on `tenants` itself, id = the caller's
 * own current_tenant_id, already makes any other tenant's row
 * unreachable here regardless).
 */
class TenantController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function update(UpdateTenantRequest $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->name = $request->validated('name');
        $tenant->save();

        return response()->json(['data' => $this->payload($request)]);
    }

    private function payload(Request $request): array
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'type' => $tenant->type,
            // Safe to return to the tenant that owns it: it's the same
            // secret whoever holds it can already use directly at
            // /quick/{token}. Never returned to anyone else — this
            // endpoint is always $request->user()->tenant, never a
            // client-supplied id.
            'quick_add_token' => $tenant->quick_add_token,
            'created_at' => $tenant->created_at,
            // For AppShell's global trial-expired banner — every
            // authenticated page reads this from here rather than a
            // separate /subscription call, since /tenant is already
            // fetched app-wide.
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'trial_ends_at' => $tenant->trial_ends_at,
            // Same "already fetched app-wide, don't add a second call"
            // reasoning as trial_ends_at above, now for the renewal
            // reminder banner (.claude/BILLING.md "Renewal reminders").
            'renewal_reminder' => $this->renewalReminder($tenant, $user),
            // The "add email verification" decision doc: null unless
            // sending is actually blocked right now — 'trial_expired',
            // 'subscription_ended', or 'email_unverified'
            // (Tenant::sendingBlockedReason(), the exact same source of
            // truth RequireSendingAccess enforces server-side). Drives
            // the email-verification banner specifically; trial_expired
            // still also reads the dedicated `status` field above for
            // TrialExpiredBanner, unchanged.
            'sending_blocked_reason' => $tenant->sendingBlockedReason(),
            // THIS user's own verification state (not necessarily the
            // tenant owner's, if a member is asking) — drives whether
            // Settings shows "verify your email"/"resend" for their own
            // account. Only meaningful for unblocking sending when
            // is_owner is also true — see Tenant::ownerEmailVerified()'s
            // own docblock for why sending is gated on the owner
            // specifically, not whichever user is asking.
            'email_verified' => $user->hasVerifiedEmail(),
            // The CALLING user's own role/permissions — not the tenant's.
            // Read fresh from the DB on every request (no session/cookie
            // caching anywhere in this chain), so SidebarShell's nav
            // gating (fetched here on every page navigation, same as the
            // trial-expired banner above) reflects a permission the owner
            // just revoked immediately, not just after the member's next
            // login. The actual enforcement is server-side regardless
            // (RequirePermission/EnsureTenantOwner on every route) — this
            // is purely what the frontend uses to decide what to show.
            'role' => $user->role,
            'is_owner' => $user->isOwner(),
            'permissions' => $user->isOwner()
                ? array_fill_keys(User::PERMISSION_RESOURCES, true)
                : array_merge(array_fill_keys(User::PERMISSION_RESOURCES, false), $user->permissions ?? []),
        ];
    }

    /**
     * The dashboard-banner counterpart to
     * billing:send-renewal-reminders' emails (.claude/BILLING.md "Renewal
     * reminders") — live-computed from current subscription state, not
     * "was the email sent." A 10-day window (not just the exact 10/5-day
     * marks the email fires on) so the banner stays visible on every
     * login in between, matching "visible from login" rather than only
     * flashing on the two exact threshold days.
     */
    private function renewalReminder(Tenant $tenant, User $user): ?array
    {
        if ($tenant->status !== 'active') {
            return null;
        }

        $subscription = $user->subscription('default');
        $periodEnd = $subscription?->periodEnd();

        if ($periodEnd === null) {
            return null;
        }

        $today = now()->startOfDay();
        $endOfDay = $periodEnd->copy()->startOfDay();

        if ($endOfDay->lessThan($today)) {
            return null;
        }

        $daysUntil = (int) $today->diffInDays($endOfDay);

        if ($daysUntil > 10) {
            return null;
        }

        $priceCents = $tenant->billing_interval !== null
            ? config("plans.standard.intervals.{$tenant->billing_interval}.price_cents")
            : null;

        return [
            'days_until' => $daysUntil,
            'auto_renew' => $subscription->autoRenews(),
            'period_end' => $periodEnd,
            'billing_interval' => $tenant->billing_interval,
            'amount_display' => $priceCents !== null ? '$'.number_format($priceCents / 100, 0) : null,
        ];
    }
}
