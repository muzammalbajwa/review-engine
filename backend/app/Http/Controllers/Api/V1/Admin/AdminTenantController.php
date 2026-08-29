<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentLog;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Audit\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * .claude/API.md: "Admin: GET /admin/tenants, GET /admin/tenants/{id}/*
 * (logged, admin only)". Deliberately does not use route-model-binding for
 * the {tenant} parameter — binding runs through the normal Eloquent query
 * before authorization even happens, and would 404 on any tenant other than
 * the caller's own. Authorization checks only the acting user (TenantPolicy
 * ::viewAny), then AdminAuditLogger performs the actual cross-tenant read
 * under a narrowly-scoped, audited RLS bypass.
 */
class AdminTenantController extends Controller
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    /**
     * The admin customer list (.claude/FRONTEND.md screen 4) — extended in
     * place, not split into a second /admin/billing-style list, per-tenant
     * billing state (plan/interval/status/renewal/auto-renew) is exactly
     * the kind of thing an admin scanning "all tenants" needs alongside
     * name/type/created_at, not a separate view to context-switch to.
     * Still one audit_logs row per call (action stays
     * 'admin.tenants.list') — the extra Subscription read below happens
     * inside the same RLS-bypassed window the tenant read already used,
     * not a second, separately-audited cross-tenant read.
     *
     * plan/billing_interval/status/trial_ends_at all live directly on
     * `tenants` (SubscriptionController::subscriptionPayload() already
     * treats these as authoritative — true even while trialing, when no
     * Subscription row exists at all) — only auto_renew/next_renewal_date
     * need the actual Subscription row (App\Models\Subscription::
     * autoRenews()/periodEnd()), same derivation Settings/Billing uses for
     * a single tenant.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);

        $data = $this->auditLogger->record(
            actor: $request->user(),
            tenantId: null,
            action: 'admin.tenants.list',
            target: 'all',
            read: function () {
                $tenants = Tenant::query()->orderBy('created_at')->get([
                    'id', 'name', 'type', 'created_at', 'plan', 'billing_interval', 'status', 'trial_ends_at',
                ]);

                // withoutGlobalScopes(): TenantScope (Layer A) otherwise
                // filters this to the acting admin's own tenant_id alone —
                // same real, previously-hit bug the deleted
                // AdminBillingController's own docblock documented, not a
                // hypothetical. subscriptions() (ManagesSubscriptions) is
                // itself ordered desc by created_at and type='default' is
                // the one real subscription type this app ever creates
                // (SubscriptionController::subscribe()'s customData) — take
                // the first (most recent) row per tenant, the exact same
                // "current subscription" a single tenant's own
                // $user->subscription('default') resolves to.
                $currentSubscriptionByTenant = Subscription::query()
                    ->withoutGlobalScopes()
                    ->whereIn('tenant_id', $tenants->pluck('id'))
                    ->where('type', Subscription::DEFAULT_TYPE)
                    ->orderByDesc('created_at')
                    ->get()
                    ->groupBy('tenant_id')
                    ->map(fn ($rows) => $rows->first());

                return $tenants->map(function (Tenant $tenant) use ($currentSubscriptionByTenant) {
                    $subscription = $currentSubscriptionByTenant->get($tenant->id);

                    return [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'type' => $tenant->type,
                        'created_at' => $tenant->created_at,
                        'plan' => $tenant->plan,
                        'billing_interval' => $tenant->billing_interval,
                        'status' => $tenant->status,
                        'trial_ends_at' => $tenant->trial_ends_at,
                        'auto_renew' => $subscription?->autoRenews(),
                        'next_renewal_date' => $subscription?->periodEnd(),
                    ];
                })->values();
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * The tenant drill-in (.claude/FRONTEND.md screen 4's "drill-in" half
     * of "all tenants, drill-in") — now also returns that tenant's full
     * payment_logs history (Step 1's append-only payment-attempt table),
     * most-recent-first. Same single audit_logs row per call as before
     * (action stays 'admin.tenants.view', target=$tenant): the
     * payment_logs read happens inside the same RLS-bypassed
     * AdminAuditLogger::record() window the tenant read already uses, not
     * a second, separately-audited cross-tenant query — exactly the
     * pattern index() above already established for its own extra
     * Subscription read.
     *
     * Returns null (not an empty array short-circuit) before ever
     * touching payment_logs if the tenant itself doesn't exist — keeps
     * the existing "null result means 404, don't write a tenant_id on
     * this audit row" behavior (AdminAuditLogger's own docblock) exactly
     * as it already worked; a nonexistent tenant obviously has no
     * payment history worth a second query.
     */
    public function show(Request $request, string $tenant): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);

        $tenantRow = $this->auditLogger->record(
            actor: $request->user(),
            tenantId: $tenant,
            action: 'admin.tenants.view',
            target: $tenant,
            read: function () use ($tenant) {
                $row = Tenant::query()->find($tenant, ['id', 'name', 'type', 'created_at']);

                if ($row === null) {
                    return null;
                }

                // withoutGlobalScopes(): same reason as index()'s own
                // Subscription read — TenantScope filters to the acting
                // admin's own tenant_id, a separate mechanism from the
                // app.is_admin RLS bypass this whole record() call already
                // set up, and would otherwise silently return zero rows
                // for every tenant except the admin's own.
                $paymentLogs = PaymentLog::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant)
                    ->orderByDesc('occurred_at')
                    ->get([
                        'paddle_transaction_id', 'amount', 'currency', 'status',
                        'failure_reason', 'billing_interval', 'occurred_at',
                    ]);

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'type' => $row->type,
                    'created_at' => $row->created_at,
                    'payment_logs' => $paymentLogs,
                ];
            },
        );

        if ($tenantRow === null) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'The requested resource was not found.',
                'fields' => null,
            ], 404);
        }

        return response()->json(['data' => $tenantRow]);
    }
}
