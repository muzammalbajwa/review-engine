<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .claude/SECURITY.md #5: "API keys ... server-side only" — the portal
 * session URL is generated here (Billable::customerPortalUrl()), never
 * assembled by the frontend. No real LEMON_SQUEEZY_API_KEY exists in this
 * test environment (.env.testing), same gap as the rest of this codebase's
 * Lemon Squeezy integration — so only the two honest failure paths are
 * exercised here, not a real portal redirect.
 */
test('the billing portal endpoint requires authentication', function () {
    $this->getJson('/api/v1/subscription/portal')->assertUnauthorized();
});

test('a tenant that has never subscribed gets a clean, actionable error, not a Lemon Squeezy call', function () {
    [$token] = seedCustomerAccount('Portal Never Subscribed');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/subscription/portal');

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('no_billing_account');
});

test('a tenant with a Lemon Squeezy customer id but no working API key gets a clean billing_unavailable error', function () {
    [$token, $tenantId] = seedCustomerAccount('Portal Lemon Squeezy Unavailable');

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        $user = User::where('tenant_id', $tenantId)->first();

        $customer = new Customer(['lemon_squeezy_id' => 'cust_fake_for_test']);
        $customer->billable_id = $user->id;
        $customer->billable_type = $user->getMorphClass();
        $customer->tenant_id = $tenantId;
        $customer->save();
    });

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/subscription/portal');

    $response->assertStatus(502);
    expect($response->json('error'))->toBe('billing_unavailable');
});
