<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * .claude/DATABASE.md: "EVERY admin cross-tenant read writes an audit_log
 * row" — same AdminAuditLogger path as AdminTenantController/
 * AdminAuditLogController, proven the same way those already are.
 *
 * Real aggregation against lemon_squeezy_subscriptions, not synthetic
 * numbers: every test here seeds actual Subscription rows (real variant_id/
 * status values) and asserts the breakdown's counts/cents against
 * hand-computed expected values from config('plans.standard.intervals').
 */
function seedSubscriptionForTenant(string $tenantId, string $variantId, string $status = 'active'): Subscription
{
    return DB::transaction(function () use ($tenantId, $variantId, $status) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $user = User::query()->first();

        $subscription = new Subscription([
            'type' => 'default',
            'lemon_squeezy_id' => 'sub_'.Str::random(14),
            'status' => $status,
            'product_id' => 'product_test',
            'variant_id' => $variantId,
        ]);
        $subscription->billable_id = $user->id;
        $subscription->billable_type = $user->getMorphClass();
        $subscription->tenant_id = $tenantId;
        $subscription->save();

        return $subscription;
    });
}

test('billing breakdown requires authentication', function () {
    $this->getJson('/api/v1/admin/billing-breakdown')->assertUnauthorized();
});

test('a non-admin tenant cannot view the billing breakdown', function () {
    [$token] = seedCustomerAccount('Non Admin Billing Breakdown');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/billing-breakdown')
        ->assertForbidden();
});

test('CRITICAL: real aggregation — counts tenants and computes MRR/ARR correctly across monthly and annual subscriptions', function () {
    [$adminToken] = seedAdminAccount('Billing Breakdown Admin');

    $monthlyVariant = config('plans.standard.intervals.monthly.variant');
    $annualVariant = config('plans.standard.intervals.annual.variant');

    [, $tenantA] = seedCustomerAccount('Billing Breakdown Monthly A');
    [, $tenantB] = seedCustomerAccount('Billing Breakdown Monthly B');
    [, $tenantC] = seedCustomerAccount('Billing Breakdown Annual A');

    seedSubscriptionForTenant($tenantA, $monthlyVariant);
    seedSubscriptionForTenant($tenantB, $monthlyVariant);
    seedSubscriptionForTenant($tenantC, $annualVariant);

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/billing-breakdown');

    $response->assertOk();

    $monthlyPriceCents = config('plans.standard.intervals.monthly.price_cents');
    $annualPriceCents = config('plans.standard.intervals.annual.price_cents');

    expect($response->json('data.by_interval.monthly.tenant_count'))->toBe(2);
    expect($response->json('data.by_interval.monthly.price_cents'))->toBe($monthlyPriceCents);
    expect($response->json('data.by_interval.monthly.mrr_cents'))->toBe($monthlyPriceCents * 2);
    expect($response->json('data.by_interval.monthly.arr_cents'))->toBe($monthlyPriceCents * 2 * 12);

    expect($response->json('data.by_interval.annual.tenant_count'))->toBe(1);
    expect($response->json('data.by_interval.annual.price_cents'))->toBe($annualPriceCents);
    // Amortized monthly-equivalent, not the raw annual price — a $200/yr
    // tenant contributes $200/12 to MRR, not $200.
    expect($response->json('data.by_interval.annual.mrr_cents'))->toBe(intdiv($annualPriceCents, 12));
    expect($response->json('data.by_interval.annual.arr_cents'))->toBe(intdiv($annualPriceCents, 12) * 12);

    $expectedTotalMrr = ($monthlyPriceCents * 2) + intdiv($annualPriceCents, 12);
    expect($response->json('data.totals.tenant_count'))->toBe(3);
    expect($response->json('data.totals.mrr_cents'))->toBe($expectedTotalMrr);
    expect($response->json('data.totals.arr_cents'))->toBe($expectedTotalMrr * 12);
    expect($response->json('data.variants_configured'))->toBeTrue();

    $logs = auditLogsForAction('admin.billing.breakdown');
    expect($logs->where('target', 'all')->isNotEmpty())->toBeTrue();
});

test('CRITICAL: when the monthly and annual variant ids are unset (or identical), every subscription is bucketed as unrecognized instead of silently misattributed to one interval', function () {
    // Reproduces the exact live bug found in dev: LEMON_SQUEEZY_VARIANT_
    // MONTHLY/_ANNUAL both blank in .env resolve to the same empty
    // string, and — before this guard existed — the variant->interval map
    // (keyed by variant id) silently let 'annual' overwrite 'monthly' on
    // that duplicate key, so a real monthly subscriber's row was counted
    // as annual and monthly showed a confidently-wrong zero.
    config([
        'plans.standard.intervals.monthly.variant' => 'same_unconfigured_variant',
        'plans.standard.intervals.annual.variant' => 'same_unconfigured_variant',
    ]);

    [$adminToken] = seedAdminAccount('Billing Breakdown Misconfigured Admin');
    [, $tenantId] = seedCustomerAccount('Billing Breakdown Misconfigured Tenant');
    seedSubscriptionForTenant($tenantId, 'same_unconfigured_variant');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/billing-breakdown');

    $response->assertOk();
    expect($response->json('data.variants_configured'))->toBeFalse();
    expect($response->json('data.by_interval.monthly.tenant_count'))->toBe(0);
    expect($response->json('data.by_interval.annual.tenant_count'))->toBe(0);
    expect($response->json('data.unrecognized_variant.tenant_count'))->toBe(1);
    // Still counted in the honest total, still contributes no fabricated
    // dollar figure — same "visible, not hidden" treatment as any other
    // unrecognized-variant subscription.
    expect($response->json('data.totals.tenant_count'))->toBe(1);
    expect($response->json('data.totals.mrr_cents'))->toBe(0);
});

test('CRITICAL: cancelled, expired, and past_due subscriptions are excluded — they never inflate MRR/ARR for a tenant no longer paying', function () {
    [$adminToken] = seedAdminAccount('Billing Breakdown Exclusions Admin');
    $monthlyVariant = config('plans.standard.intervals.monthly.variant');

    [, $tenantActive] = seedCustomerAccount('Billing Breakdown Still Active');
    [, $tenantCancelled] = seedCustomerAccount('Billing Breakdown Cancelled');
    [, $tenantExpired] = seedCustomerAccount('Billing Breakdown Expired');
    [, $tenantPastDue] = seedCustomerAccount('Billing Breakdown Past Due');

    seedSubscriptionForTenant($tenantActive, $monthlyVariant, 'active');
    seedSubscriptionForTenant($tenantCancelled, $monthlyVariant, 'cancelled');
    seedSubscriptionForTenant($tenantExpired, $monthlyVariant, 'expired');
    seedSubscriptionForTenant($tenantPastDue, $monthlyVariant, 'past_due');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/billing-breakdown');

    $response->assertOk();
    expect($response->json('data.by_interval.monthly.tenant_count'))->toBe(1);
    expect($response->json('data.totals.tenant_count'))->toBe(1);
});

test('CRITICAL: a subscription on a variant id that matches neither configured interval is bucketed separately, never silently dropped from the totals', function () {
    [$adminToken] = seedAdminAccount('Billing Breakdown Unknown Variant Admin');
    [, $tenantId] = seedCustomerAccount('Billing Breakdown Unknown Variant');

    seedSubscriptionForTenant($tenantId, 'stale_variant_from_before_the_pricing_change');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/billing-breakdown');

    $response->assertOk();
    expect($response->json('data.by_interval.monthly.tenant_count'))->toBe(0);
    expect($response->json('data.by_interval.annual.tenant_count'))->toBe(0);
    expect($response->json('data.unrecognized_variant.tenant_count'))->toBe(1);
    expect($response->json('data.unrecognized_variant.price_cents'))->toBeNull();
    // Still counted so the total tenant count is honest...
    expect($response->json('data.totals.tenant_count'))->toBe(1);
    // ...but contributes nothing to a dollar figure the data can't
    // actually support (no known price for an unrecognized variant).
    expect($response->json('data.totals.mrr_cents'))->toBe(0);
});

test('a tenant with two active subscription rows (e.g. a real-world resubscribe) is only counted once', function () {
    [$adminToken] = seedAdminAccount('Billing Breakdown Dedup Admin');
    $monthlyVariant = config('plans.standard.intervals.monthly.variant');
    [, $tenantId] = seedCustomerAccount('Billing Breakdown Dedup Tenant');

    seedSubscriptionForTenant($tenantId, $monthlyVariant);
    seedSubscriptionForTenant($tenantId, $monthlyVariant);

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/billing-breakdown');

    $response->assertOk();
    expect($response->json('data.by_interval.monthly.tenant_count'))->toBe(1);
});

test('a tenant that never converted (still trialing, no subscription row at all) contributes nothing to the breakdown', function () {
    [$adminToken] = seedAdminAccount('Billing Breakdown No Sub Admin');
    seedCustomerAccount('Billing Breakdown Never Converted');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/billing-breakdown');

    $response->assertOk();
    // DatabaseTransactions (tests/TestCase.php) gives every test a fresh,
    // rolled-back-after transaction — a genuine zero here, not just "no
    // worse than before," since nothing else in this transaction ever
    // seeded a Subscription row.
    expect($response->json('data.totals.tenant_count'))->toBe(0);
    expect($response->json('data.totals.mrr_cents'))->toBe(0);
});
