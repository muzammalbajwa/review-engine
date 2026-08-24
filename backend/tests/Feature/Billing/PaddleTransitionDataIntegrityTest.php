<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * CRITICAL — same standard as TrialExpiryDataIntegrityTest.php's own
 * "trial expiry must never delete, soft-delete, or otherwise touch any
 * tenant data," now covering every billing transition this app can drive
 * a tenant through end to end: real trial -> real product data seeded ->
 * conversion (subscription.created) -> a failed payment (past_due) ->
 * recovery (subscription.updated back to active) -> auto-renew off
 * (cancel-at-period-end, a real Paddle API call via Http::fake()) ->
 * cancellation actually taking effect (subscription.canceled). A
 * full-table snapshot (campaigns/contacts/templates/messages) is taken
 * before the FIRST transition and compared byte-for-byte after the
 * LAST one — every intermediate transition runs through the real
 * webhook controller / real SubscriptionController::update(), not a
 * direct model write, the same "exercise the actual production code
 * path" standard the original trial-expiry test set.
 */
test('CRITICAL: no billing transition (checkout, past_due, recovery, cancel-at-period-end, cancellation) ever touches tenant product data', function () {
    [$token, $tenantId] = seedCustomerAccount('Billing Lifecycle Data Integrity');
    $ownerId = ownerIdFor($tenantId);

    // Real trial start, same as the original trial-expiry test.
    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $seeded = seedTrialEraData($tenantId);
    expect($seeded['contacts'])->toHaveCount(3);

    $before = tenantDataSnapshot($tenantId);
    expect($before['campaigns'])->toHaveCount(1);
    expect($before['contacts'])->toHaveCount(3);
    expect($before['templates'])->toHaveCount(1);
    expect($before['messages'])->toHaveCount(1);

    // --- Conversion: real subscription.created webhook ---
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);
    expect(paddleWebhookTenantStatus($tenantId))->toBe('active');

    // --- A failed payment: real transaction.payment_failed webhook ---
    $customerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return \App\Models\Customer::query()->value('paddle_id');
    });

    postSignedPaddleWebhook([
        'event_id' => 'evt_failed',
        'event_type' => 'transaction.payment_failed',
        'data' => [
            'id' => 'txn_'.\Illuminate\Support\Str::random(14),
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'custom_data' => ['tenant_id' => $tenantId],
        ],
    ])->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('past_due');

    // --- Recovery: real subscription.updated webhook, status back to active ---
    postSignedPaddleWebhook([
        'event_id' => 'evt_recovered',
        'event_type' => 'subscription.updated',
        'data' => [
            'id' => $subscriptionId,
            'customer_id' => $customerId,
            'status' => 'active',
            'custom_data' => ['tenant_id' => $tenantId],
            'items' => [
                ['price' => ['id' => config('plans.standard.intervals.monthly.price'), 'product_id' => 'pro_test'], 'status' => 'active', 'quantity' => 1],
            ],
        ],
    ])->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('active');

    // --- Auto-renew off: real PATCH /subscription, real (faked) Paddle
    // cancel-at-period-end call ---
    \Illuminate\Support\Facades\Http::fake([
        'sandbox-api.paddle.com/subscriptions/*/cancel' => \Illuminate\Support\Facades\Http::response([
            'data' => [
                'status' => 'active',
                'scheduled_change' => ['action' => 'cancel', 'effective_at' => now()->addMonth()->toIso8601String()],
            ],
        ], 200),
    ]);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false])
        ->assertOk();
    // Cancel-at-period-end does NOT flip tenant.status immediately —
    // confirmed unchanged from the checkpoint before this call.
    expect(paddleWebhookTenantStatus($tenantId))->toBe('active');

    // --- The scheduled cancellation actually taking effect: real
    // subscription.canceled webhook ---
    postSignedPaddleWebhook([
        'event_id' => 'evt_canceled',
        'event_type' => 'subscription.canceled',
        'data' => [
            'id' => $subscriptionId,
            'customer_id' => $customerId,
            'status' => 'canceled',
            'canceled_at' => now()->toIso8601String(),
            'custom_data' => ['tenant_id' => $tenantId],
        ],
    ])->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('canceled');

    $after = tenantDataSnapshot($tenantId);

    // Row counts unchanged across the entire lifecycle — nothing
    // deleted, nothing extra created by any billing transition.
    expect($after['campaigns'])->toHaveCount(1);
    expect($after['contacts'])->toHaveCount(3);
    expect($after['templates'])->toHaveCount(1);
    expect($after['messages'])->toHaveCount(1);

    // Every column on every row, byte-for-byte identical to the
    // snapshot taken before conversion — proves every one of the five
    // billing transitions above touched only tenants/customers/
    // subscriptions, never campaigns/contacts/templates/messages.
    expect($after)->toEqual($before);

    // Still fully readable through the real, authenticated API too —
    // not just present in the database (same as the trial-expiry test's
    // own final check).
    $contactsResponse = test()->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/contacts');
    $contactsResponse->assertOk()->assertJsonPath('data.total', 3);

    // Access itself did correctly change, though — canceled genuinely
    // blocks sending (unlike past_due, which the earlier checkpoint
    // above never touched sending-access for). Confirms this test
    // exercised a real, meaningful end state, not a no-op sequence.
    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = Tenant::find($tenantId);
        expect($tenant->sendingBlockedReason())->toBe('subscription_ended');
    });
});
