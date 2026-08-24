<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .claude/BILLING.md "Renewal reminders": GET /tenant's renewal_reminder
 * field — the dashboard banner's data source, live-computed rather than
 * tied to whether billing:send-renewal-reminders has actually sent an
 * email (see SendRenewalRemindersTest.php for that side). Reuses
 * subscriptionWebhookPayload()/postSignedLemonSqueezyWebhook()
 * (TrialConversionTest.php — all Pest files in this suite share one
 * process, see that file's own docblock) to get a real, webhook-activated
 * subscription rather than hand-setting tenant.status.
 */
/**
 * Returns the Lemon Squeezy subscription id this activation used — a
 * caller sending a follow-up event for the SAME subscription (e.g. a
 * cancellation) must reuse it, since subscriptionWebhookPayload() mints a
 * fresh random id on every call and the package's WebhookController looks
 * the row up by that id to sync it (same gotcha AutoRenewWebhookTest.php's
 * withSameSubscriptionId() works around).
 */
function activateForRenewalReminderTest(string $token, string $tenantId, array $attributeOverrides = []): string
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
        array_merge(['variant_id' => $variantId], $attributeOverrides),
    );

    postSignedLemonSqueezyWebhook($payload)->assertOk();

    return $payload['data']['id'];
}

test('a trialing tenant (no real subscription yet) has no renewal reminder', function () {
    [$token] = seedCustomerAccount('Reminder Trialing');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');

    $response->assertOk();
    expect($response->json('data.renewal_reminder'))->toBeNull();
});

test('an active subscription renewing in 7 days shows the renewal reminder banner data', function () {
    [$token, $tenantId] = seedCustomerAccount('Reminder Within Window');
    activateForRenewalReminderTest($token, $tenantId, ['renews_at' => today()->addDays(7)->toIso8601String()]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');

    $response->assertOk();
    $reminder = $response->json('data.renewal_reminder');
    expect($reminder)->not->toBeNull();
    expect($reminder['days_until'])->toBe(7);
    expect($reminder['auto_renew'])->toBeTrue();
    expect($reminder['billing_interval'])->toBe('monthly');
    expect($reminder['amount_display'])->toBe('$20');
});

test('an active subscription renewing in 20 days shows no reminder yet', function () {
    [$token, $tenantId] = seedCustomerAccount('Reminder Outside Window');
    activateForRenewalReminderTest($token, $tenantId, ['renews_at' => today()->addDays(20)->toIso8601String()]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');

    $response->assertOk();
    expect($response->json('data.renewal_reminder'))->toBeNull();
});

test('a cancel-at-period-end subscription ending in 3 days shows auto_renew false in the reminder', function () {
    [$token, $tenantId] = seedCustomerAccount('Reminder Grace Period');
    $subscriptionId = activateForRenewalReminderTest($token, $tenantId);

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

    $cancelPayload = subscriptionWebhookPayload(
        $customData,
        ['variant_id' => $variantId, 'status' => 'cancelled', 'cancelled' => true, 'renews_at' => null, 'ends_at' => today()->addDays(3)->toIso8601String()],
        eventName: 'subscription_cancelled',
    );
    $cancelPayload['data']['id'] = $subscriptionId;

    postSignedLemonSqueezyWebhook($cancelPayload)->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');

    $response->assertOk();
    $reminder = $response->json('data.renewal_reminder');
    expect($reminder)->not->toBeNull();
    expect($reminder['days_until'])->toBe(3);
    expect($reminder['auto_renew'])->toBeFalse();
});
