<?php

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PaddleWebhookController's real dispatch, tenant-context resolution, and
 * STATUS_MAP — this task's requirement #2 (Paddle's subscription
 * lifecycle events onto tenants.status) and its explicit "a failed
 * transaction moves status to past_due, not canceled — do not collapse
 * this into trial_expired or canceled" requirement.
 *
 * Every test here goes through postSignedPaddleWebhook() (Helpers.php) —
 * a real, correctly-signed POST through VerifyWebhookSignature and the
 * actual controller/Cashier dispatch, not a direct method call — so
 * these prove the whole chain, not just the mapping logic in isolation.
 */
// ownerIdFor()/subscriptionCreatedPayload() moved to tests/Helpers.php —
// AutoRenewToggleTest.php and SendRenewalRemindersTest.php need "seed an
// active real subscription" too (same shared-helper convention this file
// already lives by, see Helpers.php's own docblock).

function subscriptionUpdatedPayload(string $customerId, string $subscriptionId, string $tenantId, string $status, string $priceId): array
{
    return [
        'event_id' => 'evt_'.Str::random(10),
        'event_type' => 'subscription.updated',
        'data' => [
            'id' => $subscriptionId,
            'customer_id' => $customerId,
            'status' => $status,
            'custom_data' => ['tenant_id' => $tenantId],
            'items' => [
                ['price' => ['id' => $priceId, 'product_id' => 'pro_test'], 'status' => 'active', 'quantity' => 1],
            ],
        ],
    ];
}

function subscriptionCanceledPayload(string $customerId, string $subscriptionId, string $tenantId): array
{
    return [
        'event_id' => 'evt_'.Str::random(10),
        'event_type' => 'subscription.canceled',
        'data' => [
            'id' => $subscriptionId,
            'customer_id' => $customerId,
            'status' => 'canceled',
            'canceled_at' => now()->toIso8601String(),
            'custom_data' => ['tenant_id' => $tenantId],
        ],
    ];
}

function transactionPaymentFailedPayload(string $customerId, string $subscriptionId, string $tenantId): array
{
    return [
        'event_id' => 'evt_'.Str::random(10),
        'event_type' => 'transaction.payment_failed',
        'data' => [
            'id' => 'txn_'.Str::random(14),
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'custom_data' => ['tenant_id' => $tenantId],
        ],
    ];
}

function transactionCompletedPayload(string $customerId, string $subscriptionId, string $tenantId): array
{
    return [
        'event_id' => 'evt_'.Str::random(10),
        'event_type' => 'transaction.completed',
        'data' => [
            'id' => 'txn_'.Str::random(14),
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'invoice_number' => 'INV-'.Str::random(6),
            'status' => 'completed',
            'details' => ['totals' => ['total' => '2000', 'tax' => '0']],
            'currency_code' => 'USD',
            'billed_at' => now()->toIso8601String(),
            'custom_data' => ['tenant_id' => $tenantId],
        ],
    ];
}

function paddleWebhookTenantStatus(string $tenantId): string
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId)->status;
    });
}

test('subscription.created activates a trialing tenant and syncs billing_interval', function () {
    [, $tenantId] = seedCustomerAccount('Webhook Created Owner');
    $ownerId = ownerIdFor($tenantId);

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        Tenant::find($tenantId)->startTrial('standard');
    });

    $paddleCustomerId = 'ctm_'.Str::random(14);
    $paddleSubscriptionId = 'sub_'.Str::random(14);
    seedPaddleCustomer($tenantId, $ownerId, $paddleCustomerId);

    $response = postSignedPaddleWebhook(
        subscriptionCreatedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId, config('plans.standard.intervals.monthly.price'))
    );

    $response->assertOk();

    DB::transaction(function () use ($tenantId, $paddleSubscriptionId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = Tenant::find($tenantId);
        expect($tenant->status)->toBe('active');
        expect($tenant->billing_interval)->toBe('monthly');

        $subscription = Subscription::query()->where('paddle_id', $paddleSubscriptionId)->first();
        expect($subscription)->not->toBeNull();
        expect($subscription->status)->toBe('active');
        expect($subscription->tenant_id)->toBe($tenantId);
        expect($subscription->items()->count())->toBe(1);
    });
});

test('transaction.payment_failed moves an active tenant to past_due, not canceled or trial_expired', function () {
    [, $tenantId] = seedCustomerAccount('Webhook Payment Failed Owner');
    $ownerId = ownerIdFor($tenantId);

    $paddleCustomerId = 'ctm_'.Str::random(14);
    $paddleSubscriptionId = 'sub_'.Str::random(14);
    seedPaddleCustomer($tenantId, $ownerId, $paddleCustomerId);

    postSignedPaddleWebhook(
        subscriptionCreatedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId, config('plans.standard.intervals.monthly.price'))
    )->assertOk();

    expect(paddleWebhookTenantStatus($tenantId))->toBe('active');

    $response = postSignedPaddleWebhook(
        transactionPaymentFailedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId)
    );

    $response->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('past_due');

    // The explicit requirement under test: past_due is its own state, not
    // silently collapsed into either of the two existing "blocked" states.
    expect(paddleWebhookTenantStatus($tenantId))->not->toBe('canceled');
    expect(paddleWebhookTenantStatus($tenantId))->not->toBe('trial_expired');

    // Confirmed product decision (not this task's default assumption):
    // past_due does NOT block sending access.
    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        expect(Tenant::find($tenantId)->sendingBlocked())->toBeFalse();
    });
});

test('transaction.payment_failed never pulls an already-canceled tenant back into past_due', function () {
    [, $tenantId] = seedCustomerAccount('Webhook Stray Failure Owner');
    $ownerId = ownerIdFor($tenantId);

    $paddleCustomerId = 'ctm_'.Str::random(14);
    $paddleSubscriptionId = 'sub_'.Str::random(14);
    seedPaddleCustomer($tenantId, $ownerId, $paddleCustomerId);

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        Tenant::find($tenantId)->update(['status' => 'canceled']);
    });

    postSignedPaddleWebhook(
        transactionPaymentFailedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId)
    )->assertOk();

    expect(paddleWebhookTenantStatus($tenantId))->toBe('canceled');
});

test('subscription.updated reporting past_due, then active again, reflects both transitions (dunning recovery)', function () {
    [, $tenantId] = seedCustomerAccount('Webhook Recovery Owner');
    $ownerId = ownerIdFor($tenantId);

    $paddleCustomerId = 'ctm_'.Str::random(14);
    $paddleSubscriptionId = 'sub_'.Str::random(14);
    $price = config('plans.standard.intervals.monthly.price');
    seedPaddleCustomer($tenantId, $ownerId, $paddleCustomerId);

    postSignedPaddleWebhook(subscriptionCreatedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId, $price))->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('active');

    postSignedPaddleWebhook(subscriptionUpdatedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId, 'past_due', $price))->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('past_due');

    postSignedPaddleWebhook(subscriptionUpdatedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId, 'active', $price))->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('active');
});

test('subscription.canceled ends access — sendingBlockedReason becomes subscription_ended', function () {
    [, $tenantId] = seedCustomerAccount('Webhook Canceled Owner');
    $ownerId = ownerIdFor($tenantId);

    $paddleCustomerId = 'ctm_'.Str::random(14);
    $paddleSubscriptionId = 'sub_'.Str::random(14);
    seedPaddleCustomer($tenantId, $ownerId, $paddleCustomerId);

    postSignedPaddleWebhook(
        subscriptionCreatedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId, config('plans.standard.intervals.monthly.price'))
    )->assertOk();

    postSignedPaddleWebhook(
        subscriptionCanceledPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId)
    )->assertOk();

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = Tenant::find($tenantId);
        expect($tenant->status)->toBe('canceled');
        expect($tenant->sendingBlockedReason())->toBe('subscription_ended');
    });
});

test('transaction.completed after a payment recovers also re-syncs status back to active', function () {
    [, $tenantId] = seedCustomerAccount('Webhook Transaction Recovery Owner');
    $ownerId = ownerIdFor($tenantId);

    $paddleCustomerId = 'ctm_'.Str::random(14);
    $paddleSubscriptionId = 'sub_'.Str::random(14);
    $price = config('plans.standard.intervals.monthly.price');
    seedPaddleCustomer($tenantId, $ownerId, $paddleCustomerId);

    postSignedPaddleWebhook(subscriptionCreatedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId, $price))->assertOk();
    postSignedPaddleWebhook(transactionPaymentFailedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId))->assertOk();
    expect(paddleWebhookTenantStatus($tenantId))->toBe('past_due');

    // Paddle flips the subscription's own status back to active as part
    // of a successful retry — subscription.updated should always land
    // too in the real world, but transaction.completed's own sync
    // (reading the subscription's current status, same as any
    // subscription.* event) is what's under test here: it must not
    // require subscription.updated to have already run first.
    DB::transaction(function () use ($paddleSubscriptionId) {
        Subscription::query()->where('paddle_id', $paddleSubscriptionId)->update(['status' => 'active']);
    });

    postSignedPaddleWebhook(transactionCompletedPayload($paddleCustomerId, $paddleSubscriptionId, $tenantId))->assertOk();

    expect(paddleWebhookTenantStatus($tenantId))->toBe('active');
});

test('a webhook for tenant A never touches tenant B\'s status — tenant isolation holds for Paddle events too', function () {
    [, $tenantIdA] = seedCustomerAccount('Webhook Isolation Tenant A');
    [, $tenantIdB] = seedCustomerAccount('Webhook Isolation Tenant B');
    $ownerIdA = ownerIdFor($tenantIdA);
    $ownerIdB = ownerIdFor($tenantIdB);

    $customerIdA = 'ctm_'.Str::random(14);
    $customerIdB = 'ctm_'.Str::random(14);
    $subscriptionIdA = 'sub_'.Str::random(14);
    $subscriptionIdB = 'sub_'.Str::random(14);
    $price = config('plans.standard.intervals.monthly.price');

    seedPaddleCustomer($tenantIdA, $ownerIdA, $customerIdA);
    seedPaddleCustomer($tenantIdB, $ownerIdB, $customerIdB);

    postSignedPaddleWebhook(subscriptionCreatedPayload($customerIdA, $subscriptionIdA, $tenantIdA, $price))->assertOk();
    postSignedPaddleWebhook(subscriptionCreatedPayload($customerIdB, $subscriptionIdB, $tenantIdB, $price))->assertOk();

    expect(paddleWebhookTenantStatus($tenantIdA))->toBe('active');
    expect(paddleWebhookTenantStatus($tenantIdB))->toBe('active');

    postSignedPaddleWebhook(transactionPaymentFailedPayload($customerIdA, $subscriptionIdA, $tenantIdA))->assertOk();

    expect(paddleWebhookTenantStatus($tenantIdA))->toBe('past_due');
    // Tenant B's own status is completely unaffected by tenant A's event.
    expect(paddleWebhookTenantStatus($tenantIdB))->toBe('active');

    // RLS-level proof, not just app-level: tenant B's own Subscription row
    // is invisible from tenant A's context even with the app scope removed.
    DB::transaction(function () use ($tenantIdA, $subscriptionIdB) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantIdA]);
        expect(Subscription::withoutGlobalScopes()->where('paddle_id', $subscriptionIdB)->first())->toBeNull();
    });
});
