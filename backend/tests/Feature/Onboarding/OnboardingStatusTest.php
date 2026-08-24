<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\GbpConnection;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .claude/FRONTEND.md guided onboarding flow. This controller only tracks
 * resumability — every underlying fact it reports (subscribed, GBP
 * status, contact count) is read live from the tables the real steps
 * already write to, not a separate cache that could drift.
 */
function registerAndGetTokenForOnboarding(string $label): array
{
    $response = test()->postJson('/api/v1/register', [
        'name' => "{$label} Owner",
        'business_name' => "{$label} Co",
        'email' => strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    return [$response->json('data.token'), $response->json('data.tenant.id')];
}

test('onboarding status requires authentication', function () {
    $this->getJson('/api/v1/onboarding/status')->assertUnauthorized();
});

test('a freshly registered tenant has a fully incomplete onboarding status', function () {
    [$token] = registerAndGetTokenForOnboarding('Fresh Onboarding');

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');

    $response->assertOk();
    expect($response->json('data.subscribed'))->toBeFalse();
    expect($response->json('data.gbp_status'))->toBe('not_connected');
    expect($response->json('data.gbp_step_done'))->toBeFalse();
    expect($response->json('data.contacts_count'))->toBe(0);
    expect($response->json('data.contacts_step_done'))->toBeFalse();
    expect($response->json('data.completed'))->toBeFalse();
});

test('status reflects a tenant on a free trial', function () {
    [$token, $tenantId] = registerAndGetTokenForOnboarding('Trialing Onboarding');

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        Tenant::find($tenantId)->startTrial('standard');
    });

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');

    $response->assertOk();
    // 'subscribed' means "picked a plan in onboarding" (trial or paid) —
    // not "has a real Lemon Squeezy subscription". A trialing tenant has
    // no Subscription row at all (the whole point of the trial decision:
    // no Lemon Squeezy Customer/Subscription until actual conversion) and
    // must still be treated as past onboarding's plan step.
    expect($response->json('data.subscribed'))->toBeTrue();
    expect($response->json('data.tenant_status'))->toBe('trialing');
    expect($response->json('data.plan'))->toBe('standard');
    expect($response->json('data.trial_ends_at'))->not->toBeNull();
});

test('status reflects a tenant who has actually converted to a real subscription', function () {
    [$token, $tenantId] = registerAndGetTokenForOnboarding('Converted Onboarding');

    // Mirrors exactly what SubscriptionController::subscribe does
    // atomically on real conversion — plan/status only ever move together,
    // never a raw Subscription row on its own (see the test this replaced:
    // creating a Subscription row without also flipping tenant.status is
    // no longer a real, reachable state in this app).
    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $user = User::query()->first();
        $subscription = new Subscription([
            'type' => 'default',
            'lemon_squeezy_id' => 'sub_'.uniqid(),
            'status' => 'active',
            'product_id' => 'product_test_placeholder',
            'variant_id' => 'variant_monthly_placeholder',
        ]);
        $subscription->billable_id = $user->id;
        $subscription->billable_type = $user->getMorphClass();
        $subscription->tenant_id = $tenantId;
        $subscription->save();

        $tenant = Tenant::find($tenantId);
        $tenant->plan = 'standard';
        $tenant->status = 'active';
        $tenant->billing_interval = 'monthly';
        $tenant->save();
    });

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');

    $response->assertOk();
    expect($response->json('data.subscribed'))->toBeTrue();
    expect($response->json('data.tenant_status'))->toBe('active');
});

test('status reflects the real GBP connection status', function () {
    [$token, $tenantId] = registerAndGetTokenForOnboarding('GBP Onboarding');

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $connection = new GbpConnection([
            'oauth_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/1',
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantId;
        $connection->save();
    });

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');

    $response->assertOk();
    expect($response->json('data.gbp_status'))->toBe('connected');
});

test('status reflects the real contact count', function () {
    [$token, $tenantId] = registerAndGetTokenForOnboarding('Contacts Onboarding');

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        foreach (range(1, 3) as $i) {
            $contact = new Contact([
                'campaign_id' => $campaign->id,
                'name' => "Contact {$i}",
                'email' => "contact{$i}-".uniqid().'@example.com',
                'status' => 'pending',
            ]);
            $contact->tenant_id = $tenantId;
            $contact->save();
        }
    });

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');

    $response->assertOk();
    expect($response->json('data.contacts_count'))->toBe(3);
});

test('marking the GBP step done persists and is reflected on the next status check', function () {
    [$token] = registerAndGetTokenForOnboarding('Skip GBP');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/gbp-step-done')
        ->assertOk()
        ->assertJsonPath('data.gbp_step_done', true);

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');
    expect($status->json('data.gbp_step_done'))->toBeTrue();
    // The underlying fact is untouched — marking the step done is not the
    // same as actually connecting.
    expect($status->json('data.gbp_status'))->toBe('not_connected');
});

test('marking the contacts step done persists and is reflected on the next status check', function () {
    [$token] = registerAndGetTokenForOnboarding('Skip Contacts');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/contacts-step-done')
        ->assertOk()
        ->assertJsonPath('data.contacts_step_done', true);

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');
    expect($status->json('data.contacts_step_done'))->toBeTrue();
    expect($status->json('data.contacts_count'))->toBe(0);
});

test('completing onboarding persists and is reflected on the next status check', function () {
    [$token] = registerAndGetTokenForOnboarding('Complete Onboarding');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/complete')
        ->assertOk()
        ->assertJsonPath('data.completed', true);

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');
    expect($status->json('data.completed'))->toBeTrue();
});

test('a tenant\'s onboarding progress never affects another tenant\'s', function () {
    [$tokenA] = registerAndGetTokenForOnboarding('Onboarding Isolation A');
    [$tokenB] = registerAndGetTokenForOnboarding('Onboarding Isolation B');

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/onboarding/complete')
        ->assertOk();

    $statusB = $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/onboarding/status');
    expect($statusB->json('data.completed'))->toBeFalse();
});
