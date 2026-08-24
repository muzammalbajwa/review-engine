<?php

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * .claude/BILLING.md's "Auto-renew toggle" — confirms this task's
 * requirement #2 against the REAL Cashier Paddle API, not assumed from
 * the previous processor's shape: Subscription::cancel(false) (Paddle's
 * actual cancel-at-period-end — POST /subscriptions/{id}/cancel,
 * effective_from: 'next_billing_period') and Subscription::
 * stopCancelation() (the real "undo it" — PATCH /subscriptions/{id},
 * scheduled_change: null). Deliberately NOT Subscription::resume(),
 * which is Paddle's *pause*-undo mechanism, a different endpoint
 * entirely — verified against the installed package source before
 * writing SubscriptionController::update(), not assumed from the
 * previous processor's single "resume" concept.
 */
function fakePaddleCancelAtPeriodEnd(string $effectiveAt): void
{
    Http::fake([
        'sandbox-api.paddle.com/subscriptions/*/cancel' => Http::response([
            'data' => [
                'status' => 'active',
                'scheduled_change' => ['action' => 'cancel', 'effective_at' => $effectiveAt],
            ],
        ], 200),
    ]);
}

function fakePaddleStopCancelation(): void
{
    Http::fake([
        'sandbox-api.paddle.com/subscriptions/*' => Http::response([
            'data' => ['status' => 'active'],
        ], 200),
    ]);
}

test('toggling auto-renew requires authentication', function () {
    test()->patchJson('/api/v1/subscription', ['auto_renew' => false])->assertStatus(401);
});

test('a member cannot toggle auto-renew — billing is owner-only', function () {
    [, $tenantId] = seedCustomerAccount('Auto Renew Owner Only Owner');
    [$memberToken] = seedTeamMember($tenantId, 'Auto Renew Owner Only Member');

    $response = test()->withHeader('Authorization', "Bearer {$memberToken}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false]);

    $response->assertStatus(403);
});

test('toggling auto-renew off with no subscription returns no_billing_account', function () {
    [$token] = seedCustomerAccount('Auto Renew No Subscription Owner');

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('no_billing_account');
});

test('auto_renew: false calls Paddle\'s real cancel-at-period-end and does not touch tenant.status', function () {
    [$token, $tenantId] = seedCustomerAccount('Auto Renew Off Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    $effectiveAt = now()->addMonth()->toIso8601String();
    fakePaddleCancelAtPeriodEnd($effectiveAt);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false]);

    $response->assertOk();
    expect($response->json('data.auto_renew'))->toBeFalse();
    // Cancel-at-period-end is NOT an immediate cancellation — tenant
    // stays 'active' until the real subscription.canceled webhook fires
    // once the scheduled date actually arrives (BILLING.md's whole point
    // of this feature).
    expect($response->json('data.status'))->toBe('active');

    Http::assertSent(function ($request) use ($subscriptionId) {
        return $request->url() === "https://sandbox-api.paddle.com/subscriptions/{$subscriptionId}/cancel"
            && $request['effective_from'] === 'next_billing_period';
    });

    DB::transaction(function () use ($tenantId, $subscriptionId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $subscription = Subscription::query()->where('paddle_id', $subscriptionId)->first();
        expect($subscription->autoRenews())->toBeFalse();
        expect($subscription->ends_at)->not->toBeNull();
    });
});

test('auto_renew: true after a scheduled cancellation calls stopCancelation, not resume', function () {
    [$token, $tenantId] = seedCustomerAccount('Auto Renew Undo Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    fakePaddleCancelAtPeriodEnd(now()->addMonth()->toIso8601String());
    test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false])
        ->assertOk();

    fakePaddleStopCancelation();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => true]);

    $response->assertOk();
    expect($response->json('data.auto_renew'))->toBeTrue();

    Http::assertSent(function ($request) use ($subscriptionId) {
        return $request->method() === 'PATCH'
            && $request->url() === "https://sandbox-api.paddle.com/subscriptions/{$subscriptionId}"
            && array_key_exists('scheduled_change', $request->data())
            && $request['scheduled_change'] === null;
    });

    // Never a POST to .../resume — that endpoint is Paddle's pause-undo
    // mechanism, not the one this toggle needs.
    Http::assertNotSent(function ($request) use ($subscriptionId) {
        return str_contains($request->url(), "subscriptions/{$subscriptionId}/resume");
    });

    DB::transaction(function () use ($tenantId, $subscriptionId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $subscription = Subscription::query()->where('paddle_id', $subscriptionId)->first();
        expect($subscription->autoRenews())->toBeTrue();
        expect($subscription->ends_at)->toBeNull();
    });
});

test('toggling to the state it is already in is idempotent and never calls Paddle', function () {
    [$token, $tenantId] = seedCustomerAccount('Auto Renew Idempotent Owner');
    $ownerId = ownerIdFor($tenantId);
    activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    Http::fake(); // any real call here fails the test via assertNothingSent below.

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => true]);

    $response->assertOk();
    Http::assertNothingSent();
});

test('auto_renew: true on an already-canceled subscription is a clean subscription_ended error, not a wrong API call', function () {
    [$token, $tenantId] = seedCustomerAccount('Auto Renew Canceled Owner');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    postSignedPaddleWebhook([
        'event_id' => 'evt_cancel',
        'event_type' => 'subscription.canceled',
        'data' => [
            'id' => $subscriptionId,
            'customer_id' => DB::transaction(function () use ($tenantId) {
                DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

                return \App\Models\Customer::query()->value('paddle_id');
            }),
            'status' => 'canceled',
            'canceled_at' => now()->toIso8601String(),
            'custom_data' => ['tenant_id' => $tenantId],
        ],
    ])->assertOk();

    Http::fake();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => true]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('subscription_ended');
    Http::assertNothingSent();
});

test('a Paddle API failure while canceling returns billing_unavailable, not a 500', function () {
    [$token, $tenantId] = seedCustomerAccount('Auto Renew Paddle Down Owner');
    $ownerId = ownerIdFor($tenantId);
    activatePaddleSubscriptionForTenant($tenantId, $ownerId);

    Http::fake([
        'sandbox-api.paddle.com/*' => Http::response(['error' => ['detail' => 'Paddle is down']], 500),
    ]);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/subscription', ['auto_renew' => false]);

    $response->assertStatus(502);
    expect($response->json('error'))->toBe('billing_unavailable');
});
