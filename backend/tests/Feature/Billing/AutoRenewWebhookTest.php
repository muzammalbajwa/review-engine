<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .claude/BILLING.md's "Auto-renew toggle" section: cancel-at-period-end
 * (Settings/Billing's toggle, or a cancellation via Lemon Squeezy's own
 * customer portal — the webhook looks identical either way) must NOT cut a
 * tenant off immediately. LemonSqueezyWebhookController::mappedStatusForCancelled()
 * is what enforces that — these tests prove it against real, signed
 * webhook payloads, reusing subscriptionWebhookPayload()/
 * postSignedLemonSqueezyWebhook()/tenantStatus() (all Pest test files in
 * this suite load into one process — see TrialConversionTest.php and
 * ExpireStaleTrialsTest.php, where these are actually defined) at the
 * same fidelity those files already reach in this environment: no real
 * LEMON_SQUEEZY_API_KEY (BILLING.md's "Test-mode Lemon Squeezy objects"),
 * but LEMON_SQUEEZY_SIGNING_SECRET IS set in .env.testing, so real
 * HMAC-SHA256 signing works for an inbound webhook regardless.
 */
/**
 * subscriptionWebhookPayload() (TrialConversionTest.php) mints a fresh
 * random lemon_squeezy_id on every call — fine for tests that only ever
 * send one event, but a real subscription's created/cancelled/expired/
 * resumed events all describe the SAME Lemon Squeezy object. The
 * package's WebhookController looks the row up by that id
 * (findSubscription()) to sync it — a mismatched id means the row is
 * silently never found and never updated. Pin it explicitly.
 */
function withSameSubscriptionId(array $payload, string $id): array
{
    $payload['data']['id'] = $id;

    return $payload;
}

function activatedCustomDataFor(string $tenantId): array
{
    $userId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->first()->id;
    });

    return [
        'tenant_id' => $tenantId,
        'billable_id' => (string) $userId,
        'billable_type' => User::class,
        'subscription_type' => 'default',
    ];
}

test('a subscription_cancelled webhook with a future ends_at (cancel-at-period-end) keeps the tenant active — the grace period', function () {
    [$token, $tenantId] = seedCustomerAccount('Autorenew Grace Period Stays Active');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $variantId = config('plans.standard.intervals.monthly.variant');
    $customData = activatedCustomDataFor($tenantId);

    $created = subscriptionWebhookPayload($customData, ['variant_id' => $variantId]);
    $subscriptionId = $created['data']['id'];
    postSignedLemonSqueezyWebhook($created)->assertOk();
    expect(tenantStatus($tenantId))->toBe('active');

    // The event under test: cancel-at-period-end. Real Lemon Squeezy sets
    // ends_at to the current billing period's end when this happens —
    // simulated here as 20 days out, well within the grace period.
    postSignedLemonSqueezyWebhook(withSameSubscriptionId(subscriptionWebhookPayload(
        $customData,
        [
            'variant_id' => $variantId,
            'status' => 'cancelled',
            'status_formatted' => 'Cancelled',
            'cancelled' => true,
            'renews_at' => null,
            'ends_at' => now()->addDays(20)->toIso8601String(),
        ],
        eventName: 'subscription_cancelled',
    ), $subscriptionId))->assertOk();

    // Still active — the whole point of cancel-at-period-end. Full
    // sending access must continue too, not just the status field.
    expect(tenantStatus($tenantId))->toBe('active');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Grace Period OK', 'phone' => '555-0111'])
        ->assertCreated();

    $subscription = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Subscription::query()->first();
    });
    expect($subscription->status)->toBe('cancelled');
    expect($subscription->onGracePeriod())->toBeTrue();
    expect($subscription->autoRenews())->toBeFalse();
});

test('a subscription_expired webhook — the grace period actually ending — flips the tenant to canceled and blocks sending', function () {
    [$token, $tenantId] = seedCustomerAccount('Autorenew Expired Blocks Sending');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $variantId = config('plans.standard.intervals.monthly.variant');
    $customData = activatedCustomDataFor($tenantId);

    $created = subscriptionWebhookPayload($customData, ['variant_id' => $variantId]);
    $subscriptionId = $created['data']['id'];
    postSignedLemonSqueezyWebhook($created)->assertOk();

    postSignedLemonSqueezyWebhook(withSameSubscriptionId(subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'cancelled', 'cancelled' => true, 'renews_at' => null, 'ends_at' => now()->addDays(20)->toIso8601String()],
        eventName: 'subscription_cancelled',
    ), $subscriptionId))->assertOk();
    expect(tenantStatus($tenantId))->toBe('active');

    // The date has now genuinely arrived — Lemon Squeezy's real trigger
    // for this is its own subscription_expired webhook, not a passing
    // timestamp our own code polls for.
    postSignedLemonSqueezyWebhook(withSameSubscriptionId(subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'expired', 'status_formatted' => 'Expired', 'cancelled' => true, 'renews_at' => null, 'ends_at' => now()->subMinute()->toIso8601String()],
        eventName: 'subscription_expired',
    ), $subscriptionId))->assertOk();

    expect(tenantStatus($tenantId))->toBe('canceled');

    $blocked = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Should Be Blocked', 'phone' => '555-0122']);
    $blocked->assertStatus(422);
    expect($blocked->json('error'))->toBe('subscription_ended');
});

test('a subscription_cancelled webhook with no ends_at (an immediate/hard cancellation, not cancel-at-period-end) cancels right away', function () {
    [$token, $tenantId] = seedCustomerAccount('Autorenew Immediate Cancel');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $variantId = config('plans.standard.intervals.monthly.variant');
    $customData = activatedCustomDataFor($tenantId);

    $created = subscriptionWebhookPayload($customData, ['variant_id' => $variantId]);
    $subscriptionId = $created['data']['id'];
    postSignedLemonSqueezyWebhook($created)->assertOk();

    postSignedLemonSqueezyWebhook(withSameSubscriptionId(subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'cancelled', 'cancelled' => true, 'renews_at' => null, 'ends_at' => null],
        eventName: 'subscription_cancelled',
    ), $subscriptionId))->assertOk();

    expect(tenantStatus($tenantId))->toBe('canceled');
});

test('a subscription_resumed webhook after cancel-at-period-end restores auto-renew and never had to touch tenant.status', function () {
    [$token, $tenantId] = seedCustomerAccount('Autorenew Resume');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $variantId = config('plans.standard.intervals.monthly.variant');
    $customData = activatedCustomDataFor($tenantId);

    $created = subscriptionWebhookPayload($customData, ['variant_id' => $variantId]);
    $subscriptionId = $created['data']['id'];
    postSignedLemonSqueezyWebhook($created)->assertOk();

    postSignedLemonSqueezyWebhook(withSameSubscriptionId(subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'cancelled', 'cancelled' => true, 'renews_at' => null, 'ends_at' => now()->addDays(20)->toIso8601String()],
        eventName: 'subscription_cancelled',
    ), $subscriptionId))->assertOk();
    expect(tenantStatus($tenantId))->toBe('active');

    postSignedLemonSqueezyWebhook(withSameSubscriptionId(subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'active', 'cancelled' => false, 'renews_at' => now()->addDays(20)->toIso8601String(), 'ends_at' => null],
        eventName: 'subscription_resumed',
    ), $subscriptionId))->assertOk();

    expect(tenantStatus($tenantId))->toBe('active');

    $subscription = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Subscription::query()->first();
    });
    expect($subscription->autoRenews())->toBeTrue();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Resumed OK', 'phone' => '555-0133'])
        ->assertCreated();
});
