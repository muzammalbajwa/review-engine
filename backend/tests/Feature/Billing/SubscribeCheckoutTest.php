<?php

use Illuminate\Support\Facades\Http;

/**
 * .claude/BILLING.md's conversion flow, rebuilt on Paddle's overlay
 * checkout (Cashier's standard integration pattern — Checkout::options()
 * is exactly the payload Paddle.js's `Paddle.Checkout.open()` expects on
 * the frontend). Billable::checkout()'s createAsCustomer() is a real,
 * synchronous outbound Paddle API call (GET then, if not found, POST
 * against /customers) — Http::fake() stands in for Paddle's sandbox API
 * itself so this test proves the real request/response shape SubscriptionController
 * builds without needing live sandbox credentials.
 */
function fakePaddleCustomerCreation(string $paddleCustomerId, string $name, string $email): void
{
    Http::fake([
        'sandbox-api.paddle.com/customers*' => Http::sequence()
            // createAsCustomer()'s lookup-by-email GET — not found, so it proceeds to create.
            ->push(['data' => []], 200)
            ->push(['data' => ['id' => $paddleCustomerId, 'name' => $name, 'email' => $email]], 200),
    ]);
}

test('subscribing requires authentication', function () {
    test()->postJson('/api/v1/subscribe', ['interval' => 'monthly'])->assertStatus(401);
});

test('a member cannot subscribe — billing is owner-only', function () {
    [, $tenantId] = seedCustomerAccount('Subscribe Owner Only Owner');
    [$memberToken] = seedTeamMember($tenantId, 'Subscribe Owner Only Member');

    $response = test()->withHeader('Authorization', "Bearer {$memberToken}")
        ->postJson('/api/v1/subscribe', ['interval' => 'monthly']);

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('owner_only');
});

test('an interval outside the configured plans is rejected', function () {
    [$token] = seedCustomerAccount('Subscribe Invalid Interval');

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/subscribe', ['interval' => 'weekly']);

    $response->assertStatus(422);
    expect($response->json('fields.interval'))->not->toBeNull();
});

test('a valid monthly interval returns Paddle overlay checkout options for the resolved price', function () {
    [$token, $tenantId] = seedCustomerAccount('Subscribe Monthly Owner');
    fakePaddleCustomerCreation('ctm_test_monthly', 'Subscribe Monthly Owner Owner', 'subscribe-monthly-owner-owner@example.com');

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/subscribe', ['interval' => 'monthly']);

    $response->assertCreated();
    expect($response->json('data.items.0.priceId'))->toBe(config('plans.standard.intervals.monthly.price'));
    expect($response->json('data.items.0.quantity'))->toBe(1);
    expect($response->json('data.customer.id'))->toBe('ctm_test_monthly');
    expect($response->json('data.customData.subscription_type'))->toBe('default');
    expect($response->json('data.customData.tenant_id'))->toBe($tenantId);
});

test('a valid annual interval resolves the annual price, not the monthly one', function () {
    [$token] = seedCustomerAccount('Subscribe Annual Owner');
    fakePaddleCustomerCreation('ctm_test_annual', 'Subscribe Annual Owner Owner', 'subscribe-annual-owner-owner@example.com');

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/subscribe', ['interval' => 'annual']);

    $response->assertCreated();
    expect($response->json('data.items.0.priceId'))->toBe(config('plans.standard.intervals.annual.price'));
    expect($response->json('data.items.0.priceId'))->not->toBe(config('plans.standard.intervals.monthly.price'));
});

test('a Paddle API failure returns a clean billing_unavailable error, not a 500', function () {
    [$token] = seedCustomerAccount('Subscribe Paddle Down Owner');

    Http::fake([
        'sandbox-api.paddle.com/*' => Http::response(['error' => ['detail' => 'Paddle is down']], 500),
    ]);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/subscribe', ['interval' => 'monthly']);

    $response->assertStatus(502);
    expect($response->json('error'))->toBe('billing_unavailable');
});
