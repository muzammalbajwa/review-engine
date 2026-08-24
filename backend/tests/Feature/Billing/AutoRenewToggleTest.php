<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /subscription (SubscriptionController::update) — Settings/Billing's
 * auto-renew toggle. .claude/BILLING.md's "Auto-renew toggle" section.
 *
 * No real LEMON_SQUEEZY_API_KEY exists in this environment (see
 * SubscriptionPortalTest.php's identical gap for the portal endpoint), so
 * an outbound cancel()/resume() call that actually needs to reach Lemon
 * Squeezy can only be proven to fail honestly (billing_unavailable), not
 * to succeed — same fidelity every other real-API-call test in this repo
 * reaches. The idempotent no-op paths need no outbound call at all, so
 * those ARE proven fully here, including that they skip the API call
 * entirely (real API key or not, they must never place a needless request
 * — .claude/BILLING.md).
 */

/**
 * Returns the Lemon Squeezy subscription id the activation webhook used —
 * a caller that needs to send a follow-up event (e.g. simulating a
 * cancellation that already happened via Lemon Squeezy's own customer
 * portal) must reuse it: the package's WebhookController looks the row up
 * by lemon_squeezy_id to sync it, and subscriptionWebhookPayload() mints a
 * fresh random one on every call (same gotcha AutoRenewWebhookTest.php's
 * withSameSubscriptionId() works around).
 */
function activateSubscriptionForToggleTest(string $token, string $tenantId): string
{
    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $userId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->first()->id;
    });

    $variantId = config('plans.standard.intervals.monthly.variant');

    $payload = subscriptionWebhookPayload(
        [
            'tenant_id' => $tenantId,
            'billable_id' => (string) $userId,
            'billable_type' => User::class,
            'subscription_type' => 'default',
        ],
        ['variant_id' => $variantId],
    );

    postSignedLemonSqueezyWebhook($payload)->assertOk();

    return $payload['data']['id'];
}

test('the auto-renew toggle endpoint requires authentication', function () {
    $this->patchJson('/api/v1/subscription', ['auto_renew' => false])->assertUnauthorized();
});

test('auto_renew is required and must be boolean', function () {
    [$token] = seedCustomerAccount('Toggle Validation');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', []);
    $response->assertStatus(422);
    expect($response->json('fields.auto_renew'))->not->toBeNull();
});

test('a tenant with no subscription yet gets a clean, actionable error, not a Lemon Squeezy call', function () {
    [$token] = seedCustomerAccount('Toggle Never Subscribed');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('no_billing_account');
});

test('turning auto_renew on when it is already on is a no-op — no Lemon Squeezy call needed, so it succeeds even without an API key', function () {
    [$token, $tenantId] = seedCustomerAccount('Toggle Already On');
    activateSubscriptionForToggleTest($token, $tenantId);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => true]);

    $response->assertOk();
    expect($response->json('data.auto_renew'))->toBeTrue();
});

test('turning auto_renew off calls Lemon Squeezy cancel-at-period-end — with no test-mode API key configured, this is an honest billing_unavailable, not a silent success', function () {
    [$token, $tenantId] = seedCustomerAccount('Toggle Off No Api Key');
    activateSubscriptionForToggleTest($token, $tenantId);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false]);

    $response->assertStatus(502);
    expect($response->json('error'))->toBe('billing_unavailable');

    // The failed outbound call must never have flipped tenant.status —
    // access continues exactly as if the toggle had never been touched.
    $tenant = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId);
    });
    expect($tenant->status)->toBe('active');
});

test('turning auto_renew off when it is already off (already cancelled) is a no-op', function () {
    [$token, $tenantId] = seedCustomerAccount('Toggle Already Off');
    $subscriptionId = activateSubscriptionForToggleTest($token, $tenantId);

    $userId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->first()->id;
    });
    $variantId = config('plans.standard.intervals.monthly.variant');
    $customData = [
        'tenant_id' => $tenantId,
        'billable_id' => (string) $userId,
        'billable_type' => User::class,
        'subscription_type' => 'default',
    ];

    // Simulate cancel-at-period-end having already happened (e.g. via
    // Lemon Squeezy's own customer portal) — a signed webhook, same as
    // AutoRenewWebhookTest.php, reusing the same lemon_squeezy_id the
    // activation webhook created.
    $cancelPayload = subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'cancelled', 'cancelled' => true, 'renews_at' => null, 'ends_at' => now()->addDays(15)->toIso8601String()],
        eventName: 'subscription_cancelled',
    );
    $cancelPayload['data']['id'] = $subscriptionId;

    postSignedLemonSqueezyWebhook($cancelPayload)->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false]);

    $response->assertOk();
    expect($response->json('data.auto_renew'))->toBeFalse();
    expect($response->json('data.status'))->toBe('active');
});

test('GET /subscription reports auto_renew and current_period_end for an actively renewing subscription', function () {
    [$token, $tenantId] = seedCustomerAccount('Toggle Show Payload');
    activateSubscriptionForToggleTest($token, $tenantId);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/subscription');

    $response->assertOk();
    expect($response->json('data.auto_renew'))->toBeTrue();
    expect($response->json('data.current_period_end'))->not->toBeNull();
    expect($response->json('data.status'))->toBe('active');
});
