<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Audit\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Cross-tenant by nature (a per-tenant read could never answer "how many
 * tenants total are on monthly vs. annual"), so this goes through the same
 * audited, RLS-bypassed path every other admin cross-tenant read does
 * (AdminAuditLogger — see AdminTenantController/AdminAuditLogController)
 * rather than a new one.
 *
 * Aggregates in PHP over rows pulled via Eloquent (Subscription::query()
 * ->get(...)), not a SQL-level GROUP BY/COUNT — same style
 * AdminSystemController::status() already established for admin
 * aggregation (its failed_jobs->by_job breakdown), and .claude/SECURITY.md
 * #1's "no raw SQL" preference extends naturally to not reaching for
 * DB::raw() aggregate expressions either. Fine at this app's admin-only,
 * low-traffic scale; if the tenant count ever grows large enough for this
 * to matter, a real GROUP BY becomes worth it.
 */
class AdminBillingController extends Controller
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    public function breakdown(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);

        $rows = $this->auditLogger->record(
            actor: $request->user(),
            tenantId: null,
            action: 'admin.billing.breakdown',
            target: 'all',
            // .claude/BILLING.md's state machine: a real
            // lemon_squeezy_subscriptions row only ever exists once a
            // tenant actually converts (trial is card-less, tracked
            // entirely on `tenants`). status='active' is the one status
            // that means "currently paying" — cancelled/expired/paused
            // rows exist in this table too (BILLING.md's webhook->status
            // mapping keeps them, doesn't delete them) and must not
            // inflate MRR/ARR for a tenant no longer actually billing.
            // withoutGlobalScopes(): TenantScope (Layer A, BelongsToTenant)
            // filters to the *acting admin's own* tenant_id regardless of
            // the RLS bypass above (a separate mechanism — see
            // AdminAuditLogController's identical need for this on its
            // eager-loaded 'actor' relation). Without it, this always
            // returns zero rows for every tenant except the admin's own,
            // which never has a real subscription — confirmed live via
            // this exact bug during development, not a hypothetical.
            read: fn () => Subscription::query()
                ->withoutGlobalScopes()
                ->where('status', Subscription::STATUS_ACTIVE)
                ->get(['tenant_id', 'variant_id']),
        );

        return response()->json(['data' => $this->buildBreakdown($rows)]);
    }

    private function buildBreakdown($rows): array
    {
        $intervals = config('plans.standard.intervals');
        $monthlyVariant = (string) $intervals['monthly']['variant'];
        $annualVariant = (string) $intervals['annual']['variant'];

        // Real misconfiguration guard, not a hypothetical — caught live in
        // this exact dev environment (LEMON_SQUEEZY_VARIANT_MONTHLY/
        // _ANNUAL both unset in .env, so both resolve to the same empty
        // string). Without this, mapWithKeys()'s last-write-wins on the
        // resulting duplicate array key silently bucketed every active
        // subscription as 'annual' — confidently wrong (monthly showing 0)
        // rather than surfacing the real ambiguity: two different
        // intervals resolving to the same variant id means this endpoint
        // genuinely cannot tell them apart.
        $variantsConfigured = $monthlyVariant !== '' && $annualVariant !== '' && $monthlyVariant !== $annualVariant;

        $variantToInterval = $variantsConfigured
            ? collect($intervals)->mapWithKeys(fn (array $interval, string $key) => [(string) $interval['variant'] => $key])
            : collect();

        // Keyed by interval (or 'unknown' for a variant_id that matches
        // neither configured interval — a stale/pre-pricing-change
        // subscription, or a data anomaly). Never silently dropped: an
        // unrecognized active subscription is real revenue this endpoint
        // would otherwise quietly undercount, so it gets its own bucket
        // with a null price rather than vanishing or being guessed at.
        $grouped = $rows->groupBy(
            fn ($row) => $variantToInterval->get((string) $row->variant_id, 'unknown')
        );

        $buckets = [];
        $totalTenants = 0;
        $totalMrrCents = 0;

        foreach (['monthly', 'annual', 'unknown'] as $key) {
            $tenantCount = $grouped->get($key, collect())->pluck('tenant_id')->unique()->count();
            $priceCents = $key === 'unknown' ? null : $intervals[$key]['price_cents'];

            // Monthly-equivalent recurring value: a monthly tenant
            // contributes its price directly; an annual tenant's price is
            // amortized over 12 months so the two are comparable in one
            // MRR figure. An 'unknown' bucket has no known price, so it
            // contributes 0 to MRR/ARR but still counts toward
            // total_tenants — visible, not hidden inside a total that
            // implies more confidence than the data supports.
            $mrrCents = $priceCents === null
                ? 0
                : ($key === 'annual' ? intdiv($priceCents, 12) : $priceCents) * $tenantCount;

            $buckets[$key] = [
                'tenant_count' => $tenantCount,
                'price_cents' => $priceCents,
                'mrr_cents' => $mrrCents,
                'arr_cents' => $mrrCents * 12,
            ];

            $totalTenants += $tenantCount;
            $totalMrrCents += $mrrCents;
        }

        return [
            'by_interval' => [
                'monthly' => $buckets['monthly'],
                'annual' => $buckets['annual'],
            ],
            // Surfaced separately from by_interval, not folded into either
            // bucket — an admin needs to see this exists at all, not just
            // have it silently change monthly or annual's own numbers.
            'unrecognized_variant' => $buckets['unknown'],
            // False means LEMON_SQUEEZY_VARIANT_MONTHLY/_ANNUAL aren't
            // both set to distinct real values yet — every active
            // subscription below landed in unrecognized_variant, not
            // because the data is wrong, but because this endpoint can't
            // yet tell monthly and annual apart. The frontend uses this to
            // show a config warning instead of a confidently-split
            // monthly/annual chart that isn't backed by real distinction.
            'variants_configured' => $variantsConfigured,
            'totals' => [
                'tenant_count' => $totalTenants,
                'mrr_cents' => $totalMrrCents,
                'arr_cents' => $totalMrrCents * 12,
            ],
        ];
    }
}
