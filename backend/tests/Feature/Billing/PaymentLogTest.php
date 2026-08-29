<?php

use App\Models\PaymentLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * payment_logs — the durable, append-only payment-attempt history that
 * (confirmed before building anything) didn't exist before this table:
 * Cashier's own `transactions` table only ever records a completed
 * charge, mutated in place by later updates, and a failed attempt wrote
 * no database row at all (see PaddleWebhookController's own docblocks).
 *
 * Every test here goes through postSignedPaddleWebhook() — a real,
 * correctly-signed POST through VerifyWebhookSignature and the actual
 * controller/Cashier dispatch, same standard as PaddleWebhookStatusTest.
 */
function paymentLogsFor(string $tenantId): \Illuminate\Support\Collection
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return PaymentLog::query()->orderBy('id')->get();
    });
}

test('a real successful transaction produces exactly one succeeded row', function () {
    [, $tenantId] = seedCustomerAccount('Payment Log Success Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    $customerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $tenantId)->value('paddle_id');
    });

    $response = postSignedPaddleWebhook(
        transactionCompletedPayload($customerId, $subscriptionId, $tenantId)
    );
    $response->assertOk();

    $logs = paymentLogsFor($tenantId);

    expect($logs)->toHaveCount(1);
    expect($logs->first()->status)->toBe(PaymentLog::STATUS_SUCCEEDED);
    expect($logs->first()->amount)->toBe('2000');
    expect($logs->first()->currency)->toBe('USD');
    expect($logs->first()->failure_reason)->toBeNull();
    expect($logs->first()->billing_interval)->toBe('monthly');
    expect($logs->first()->occurred_at)->not->toBeNull();
});

test('a real failed payment produces exactly one failed row with a real failure reason populated', function () {
    [, $tenantId] = seedCustomerAccount('Payment Log Failure Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    $customerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $tenantId)->value('paddle_id');
    });

    $response = postSignedPaddleWebhook(
        transactionPaymentFailedPayload($customerId, $subscriptionId, $tenantId, errorCode: 'insufficient_funds')
    );
    $response->assertOk();

    $logs = paymentLogsFor($tenantId);

    expect($logs)->toHaveCount(1);
    expect($logs->first()->status)->toBe(PaymentLog::STATUS_FAILED);
    expect($logs->first()->failure_reason)->toBe('insufficient_funds');
    expect($logs->first()->amount)->toBe('2000');
    expect($logs->first()->currency)->toBe('USD');

    // The pre-existing tenant.status side effect still holds — this table
    // is additive, not a replacement for it.
    expect(DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId)->status;
    }))->toBe('past_due');
});

test('a retried webhook delivery for the same transaction does not create a duplicate row', function () {
    [, $tenantId] = seedCustomerAccount('Payment Log Retry Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    $customerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $tenantId)->value('paddle_id');
    });

    $payload = transactionCompletedPayload($customerId, $subscriptionId, $tenantId);

    // Same exact payload (same paddle_transaction_id), posted twice — the
    // real shape of a Paddle webhook retry (network blip, non-2xx on the
    // first attempt), not a second, distinct transaction.
    postSignedPaddleWebhook($payload)->assertOk();
    postSignedPaddleWebhook($payload)->assertOk();

    expect(paymentLogsFor($tenantId))->toHaveCount(1);
});

test('a refund is logged as its own row via a real adjustment.updated event, distinct from the original succeeded row', function () {
    [, $tenantId] = seedCustomerAccount('Payment Log Refund Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    $customerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $tenantId)->value('paddle_id');
    });

    $completedPayload = transactionCompletedPayload($customerId, $subscriptionId, $tenantId);
    postSignedPaddleWebhook($completedPayload)->assertOk();
    $transactionId = $completedPayload['data']['id'];

    // Real flow: an adjustment is created pending review, then approved —
    // two separate webhook deliveries, exactly as Paddle sends them.
    postSignedPaddleWebhook(
        adjustmentPayload('adjustment.created', $customerId, $subscriptionId, $transactionId, $tenantId, status: 'pending_approval')
    )->assertOk();
    postSignedPaddleWebhook(
        adjustmentPayload('adjustment.updated', $customerId, $subscriptionId, $transactionId, $tenantId, status: 'approved')
    )->assertOk();

    $logs = paymentLogsFor($tenantId);

    expect($logs)->toHaveCount(2);
    expect($logs->pluck('status')->all())->toBe([PaymentLog::STATUS_SUCCEEDED, PaymentLog::STATUS_REFUNDED]);
    expect($logs->last()->paddle_transaction_id)->toBe($transactionId);
});

test('a pending (not yet approved) adjustment does not create a refunded row', function () {
    [, $tenantId] = seedCustomerAccount('Payment Log Pending Refund Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    $customerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $tenantId)->value('paddle_id');
    });

    $completedPayload = transactionCompletedPayload($customerId, $subscriptionId, $tenantId);
    postSignedPaddleWebhook($completedPayload)->assertOk();
    $transactionId = $completedPayload['data']['id'];

    postSignedPaddleWebhook(
        adjustmentPayload('adjustment.created', $customerId, $subscriptionId, $transactionId, $tenantId, status: 'pending_approval')
    )->assertOk();

    $logs = paymentLogsFor($tenantId);

    expect($logs)->toHaveCount(1);
    expect($logs->first()->status)->toBe(PaymentLog::STATUS_SUCCEEDED);
});
