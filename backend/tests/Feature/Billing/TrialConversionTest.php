<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The conversion flow (.claude/BILLING.md's "Conversion" section) —
 * rewritten for Lemon Squeezy's checkout+webhook shape. POST /subscribe no
 * longer activates a tenant synchronously the way Cashier's
 * newSubscription()->create() did; it returns a checkout URL, and a real,
 * signed Lemon Squeezy webhook is what actually flips tenant.status.
 *
 * Two things are tested at the fidelity each can honestly reach in this
 * environment:
 *  1. POST /subscribe creating a real Lemon Squeezy Checkout via their
 *     live test-mode API — needs a real LEMON_SQUEEZY_API_KEY (not present
 *     in this environment yet, see BILLING.md's "Test-mode Lemon Squeezy
 *     objects"); markTestSkipped without one, same pattern the old Stripe
 *     test used for a missing cashier.secret.
 *  2. The webhook path that actually performs activation/cancellation —
 *     real HMAC-SHA256 signature verification (the same mechanism
 *     LemonSqueezyWebhookSignatureTest proves in isolation), real RLS
 *     tenant-context resolution from custom_data, real tenant.status/
 *     billing_interval writes, and the full data-retention proof — all
 *     run for real here, against a locally-constructed but correctly-signed
 *     payload (custom_data shaped exactly as $user->subscribe() actually
 *     produces it — see ManagesCheckouts::checkout() in the package). This
 *     is what proves the CRITICAL property: conversion never touches
 *     pre-existing trial-era data.
 */
function seedConversionTrialData(string $tenantId): array
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        $contact = new Contact([
            'campaign_id' => $campaign->id,
            'name' => 'Pre-Conversion Contact',
            'phone' => '555-0177',
            'status' => 'sent',
            'consent_at' => now(),
        ]);
        $contact->tenant_id = $tenantId;
        $contact->save();

        $template = new Template([
            'campaign_id' => $campaign->id,
            'step' => 1,
            'body' => 'Pre-conversion template body — {{review_link}}',
            'compliance_status' => 'pass',
        ]);
        $template->tenant_id = $tenantId;
        $template->save();

        $message = new Message([
            'contact_id' => $contact->id,
            'step' => 1,
            'status' => 'sent',
            'sent_at' => now(),
            'provider_id' => 'provider-msg-'.uniqid(),
        ]);
        $message->tenant_id = $tenantId;
        $message->save();

        return compact('campaign', 'contact', 'template', 'message');
    });
}

/**
 * Shape matches a real Lemon Squeezy subscription webhook exactly
 * (docs.lemonsqueezy.com/help/webhooks/example-payloads), with
 * meta.custom_data built the same way ManagesCheckouts::checkout() builds
 * it for real: billable_id/billable_type (always merged in by the
 * package) plus tenant_id (SubscriptionController::subscribe's own
 * addition) and subscription_type (added by ->subscribe(), not ->checkout()
 * directly).
 */
function subscriptionWebhookPayload(array $customData, array $attributeOverrides = [], string $eventName = 'subscription_created'): array
{
    return [
        'meta' => [
            'event_name' => $eventName,
            'custom_data' => $customData,
        ],
        'data' => [
            'type' => 'subscriptions',
            'id' => 'ls_sub_'.Str::random(10),
            'attributes' => array_merge([
                'store_id' => 1,
                'customer_id' => 1,
                'order_id' => 1,
                'order_item_id' => 1,
                'product_id' => 1,
                'variant_id' => '',
                'product_name' => 'ReviewEngine',
                'variant_name' => 'Default',
                'user_name' => 'Test User',
                'user_email' => 'test@example.com',
                'status' => 'active',
                'status_formatted' => 'Active',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'pause' => null,
                'cancelled' => false,
                'trial_ends_at' => null,
                'renews_at' => now()->addMonth()->toIso8601String(),
                'ends_at' => null,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'test_mode' => true,
            ], $attributeOverrides),
        ],
    ];
}

function postSignedLemonSqueezyWebhook(array $payload)
{
    $body = json_encode($payload);
    $secret = config('lemon-squeezy.signing_secret');
    $signature = hash_hmac('sha256', $body, $secret);

    return test()->call('POST', '/api/v1/lemon-squeezy/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature,
    ], $body);
}

test('POST /subscribe creates a real Lemon Squeezy checkout for the chosen interval', function () {
    if (blank(config('lemon-squeezy.api_key'))) {
        test()->markTestSkipped('No Lemon Squeezy test-mode API key configured in this environment.');
    }

    [$token, $tenantId] = seedCustomerAccount('Real Checkout Creation');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/subscribe', ['interval' => 'monthly']);

    $response->assertCreated();
    expect($response->json('data.checkout_url'))->toStartWith('https://');

    // Conversion is no longer synchronous — only the webhook (tested
    // below) activates the tenant. Creating the checkout alone must not.
    $tenant = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId);
    });
    expect($tenant->status)->toBe('trialing');
});

test('CRITICAL: a real, signed subscription_created webhook converts a trialing tenant to active (monthly) with all pre-existing trial-era data untouched', function () {
    [$token, $tenantId] = seedCustomerAccount('Webhook Conversion Trialing Monthly');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $seeded = seedConversionTrialData($tenantId);

    $userId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->first()->id;
    });

    $variantId = config('plans.standard.intervals.monthly.variant');

    $payload = subscriptionWebhookPayload(
        customData: [
            'tenant_id' => $tenantId,
            'billable_id' => (string) $userId,
            'billable_type' => User::class,
            'subscription_type' => 'default',
        ],
        attributeOverrides: ['variant_id' => $variantId, 'variant_name' => 'Monthly'],
    );

    $response = postSignedLemonSqueezyWebhook($payload);
    $response->assertOk();

    $tenant = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId);
    });

    expect($tenant->status)->toBe('active');
    expect($tenant->plan)->toBe('standard');
    expect($tenant->billing_interval)->toBe('monthly');
    // Historical record of the trial, deliberately untouched by conversion
    // (SubscriptionController::subscribe's own docblock).
    expect($tenant->trial_started_at)->not->toBeNull();
    expect($tenant->trial_ends_at)->not->toBeNull();

    // A real lemon_squeezy_subscriptions row now exists, correctly scoped
    // to this tenant — proves LemonSqueezyWebhookController's tenant-context
    // bridge actually let the package's own RLS-protected write through.
    $subscription = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Subscription::query()->first();
    });
    expect($subscription)->not->toBeNull();
    expect($subscription->status)->toBe('active');
    expect($subscription->variant_id)->toBe($variantId);

    // Every piece of trial-era data is still attached to the exact same
    // tenant_id — nothing re-created, nothing reset.
    $after = DB::transaction(function () use ($seeded) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return [
            'campaign' => Campaign::withoutGlobalScopes()->find($seeded['campaign']->id),
            'contact' => Contact::withoutGlobalScopes()->find($seeded['contact']->id),
            'template' => Template::withoutGlobalScopes()->find($seeded['template']->id),
            'message' => Message::withoutGlobalScopes()->find($seeded['message']->id),
        ];
    });

    expect($after['campaign'])->not->toBeNull();
    expect($after['campaign']->tenant_id)->toBe($tenantId);
    expect($after['contact'])->not->toBeNull();
    expect($after['contact']->tenant_id)->toBe($tenantId);
    expect($after['contact']->name)->toBe('Pre-Conversion Contact');
    expect($after['template'])->not->toBeNull();
    expect($after['template']->tenant_id)->toBe($tenantId);
    expect($after['template']->body)->toBe('Pre-conversion template body — {{review_link}}');
    expect($after['message'])->not->toBeNull();
    expect($after['message']->tenant_id)->toBe($tenantId);

    // Full access is restored — the exact endpoint the trial-expiry gate
    // blocks now works again.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Post-Conversion Contact', 'phone' => '555-0188'])
        ->assertCreated();
});

test('a real, signed subscription_created webhook converts an already trial_expired tenant to active (annual) and restores full sending access', function () {
    [$token, $tenantId] = seedCustomerAccount('Webhook Conversion Trial Expired Annual');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        $tenant = Tenant::find($tenantId);
        $tenant->trial_started_at = now()->subDays(8);
        $tenant->trial_ends_at = now()->subDay();
        $tenant->save();
    });

    $this->artisan('trial:expire')->assertSuccessful();

    $blocked = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Should Be Blocked', 'phone' => '555-0100']);
    $blocked->assertStatus(422);
    expect($blocked->json('error'))->toBe('trial_expired');

    $userId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->first()->id;
    });

    $variantId = config('plans.standard.intervals.annual.variant');

    $payload = subscriptionWebhookPayload(
        customData: [
            'tenant_id' => $tenantId,
            'billable_id' => (string) $userId,
            'billable_type' => User::class,
            'subscription_type' => 'default',
        ],
        attributeOverrides: ['variant_id' => $variantId, 'variant_name' => 'Annual'],
    );

    postSignedLemonSqueezyWebhook($payload)->assertOk();

    $tenant = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId);
    });
    expect($tenant->status)->toBe('active');
    expect($tenant->billing_interval)->toBe('annual');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Now Allowed', 'phone' => '555-0199'])
        ->assertCreated();
});

test('a real, signed subscription_cancelled webhook flips an active tenant to canceled', function () {
    [$token, $tenantId] = seedCustomerAccount('Webhook Cancellation');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

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

    // Real activation first — the CRITICAL cancellation test needs a real
    // active tenant + a real lemon_squeezy_subscriptions row to cancel,
    // not a hand-set tenant.status.
    postSignedLemonSqueezyWebhook(
        subscriptionWebhookPayload($customData, ['variant_id' => $variantId])
    )->assertOk();

    $activeStatus = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId)->status;
    });
    expect($activeStatus)->toBe('active');

    // Now the real event under test: Lemon Squeezy's own lifecycle always
    // sends subscription_cancelled AND subscription_updated together (per
    // their docs) — only subscription_cancelled here, matching
    // BILLING.md's webhook -> status mapping table (keyed off
    // data.attributes.status, not meta.event_name, so this is a faithful
    // single-event proof of that same mapping).
    postSignedLemonSqueezyWebhook(subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'cancelled', 'status_formatted' => 'Cancelled', 'cancelled' => true],
        eventName: 'subscription_cancelled',
    ))->assertOk();

    $tenant = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId);
    });
    expect($tenant->status)->toBe('canceled');
    // billing_interval is a historical record too — cancellation doesn't
    // erase which interval the tenant had been on.
    expect($tenant->billing_interval)->toBe('monthly');
});

test('a subscription_updated webhook reporting past_due does not change tenant.status (confirmed no-op decision)', function () {
    [$token, $tenantId] = seedCustomerAccount('Webhook Past Due No-op');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

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

    postSignedLemonSqueezyWebhook(
        subscriptionWebhookPayload($customData, ['variant_id' => $variantId])
    )->assertOk();

    postSignedLemonSqueezyWebhook(subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'past_due', 'status_formatted' => 'Past due'],
        eventName: 'subscription_updated',
    ))->assertOk();

    $tenant = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId);
    });

    // .claude/BILLING.md's webhook -> status mapping: past_due is a
    // deliberate no-op, not a downgrade to trial_expired/canceled.
    expect($tenant->status)->toBe('active');
});
