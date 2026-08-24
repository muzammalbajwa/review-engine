<?php

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * trial:expire (routes/console.php: daily). Same "travel time forward,
 * run the scheduled command" verification the task calls for — real
 * Carbon time travel via $this->travelTo(), not a mocked clock.
 */
function startTrialDirect(string $tenantId, string $plan = 'standard', ?Carbon $startedAt = null): void
{
    DB::transaction(function () use ($tenantId, $plan, $startedAt) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = Tenant::find($tenantId);
        $tenant->plan = $plan;
        $tenant->status = 'trialing';
        $tenant->trial_started_at = $startedAt ?? now();
        $tenant->trial_ends_at = ($startedAt ?? now())->copy()->addDays(7);
        $tenant->save();
    });
}

function tenantStatus(string $tenantId): string
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId)->status;
    });
}

test('a trial still within its 7 days is left alone', function () {
    [, $tenantId] = seedCustomerAccount('Expire Still Active');
    startTrialDirect($tenantId);

    $this->artisan('trial:expire')->assertSuccessful();

    expect(tenantStatus($tenantId))->toBe('trialing');
});

test('a trial past trial_ends_at transitions to trial_expired', function () {
    [, $tenantId] = seedCustomerAccount('Expire Past Due');
    startTrialDirect($tenantId, 'standard', now()->subDays(8));

    $this->artisan('trial:expire')->assertSuccessful();

    expect(tenantStatus($tenantId))->toBe('trial_expired');
});

test('travelling time forward past trial_ends_at then running the command expires the trial', function () {
    [$token, $tenantId] = seedCustomerAccount('Expire Time Travel');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    expect(tenantStatus($tenantId))->toBe('trialing');

    $this->travel(8)->days();

    $this->artisan('trial:expire')->assertSuccessful();

    expect(tenantStatus($tenantId))->toBe('trial_expired');
});

test('a tenant who never picked a plan (status=pending) is never touched', function () {
    [, $tenantId] = seedCustomerAccount('Expire Never Subscribed');

    $this->artisan('trial:expire')->assertSuccessful();

    expect(tenantStatus($tenantId))->toBe('pending');
});

test('a tenant who already converted to a real active subscription is never touched, even past the old trial window', function () {
    [, $tenantId] = seedCustomerAccount('Expire Already Active');
    startTrialDirect($tenantId, 'standard', now()->subDays(8));

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        $tenant = Tenant::find($tenantId);
        $tenant->status = 'active';
        $tenant->save();
    });

    $this->artisan('trial:expire')->assertSuccessful();

    expect(tenantStatus($tenantId))->toBe('active');
});

test('one tenant expiring never affects another tenant\'s still-running trial', function () {
    [, $expiredId] = seedCustomerAccount('Expire Isolation Expired');
    [, $stillRunningId] = seedCustomerAccount('Expire Isolation Running');
    startTrialDirect($expiredId, 'standard', now()->subDays(8));
    startTrialDirect($stillRunningId, 'standard', now());

    $this->artisan('trial:expire')->assertSuccessful();

    expect(tenantStatus($expiredId))->toBe('trial_expired');
    expect(tenantStatus($stillRunningId))->toBe('trialing');
});

test('running the command twice on an already-expired trial is a harmless no-op', function () {
    [, $tenantId] = seedCustomerAccount('Expire Idempotent');
    startTrialDirect($tenantId, 'standard', now()->subDays(8));

    $this->artisan('trial:expire')->assertSuccessful();
    expect(tenantStatus($tenantId))->toBe('trial_expired');

    $this->artisan('trial:expire')->assertSuccessful();
    expect(tenantStatus($tenantId))->toBe('trial_expired');
});
