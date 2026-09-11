<?php

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * .claude/BILLING.md "Renewal reminders", rebuilt against
 * laravel/cashier-paddle. Same direct-seed style as the previous
 * processor's version of this test (git history) — this needs precise
 * control over renews_at/ends_at, which a signed-webhook-driven seed
 * (PaddleWebhookStatusTest.php's own style) would make far more
 * roundabout for no benefit here: the webhook handling itself (including
 * renews_at syncing) is already covered elsewhere.
 *
 * Subscription::STATUS_CANCELED (American spelling — confirmed against
 * the installed package's real constants), not the previous processor's
 * STATUS_CANCELLED.
 */
function seedActiveSubscription(string $label, array $tenantOverrides = [], array $subscriptionOverrides = []): array
{
    $tenantId = (string) Str::uuid();
    $email = strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com';

    return DB::transaction(function () use ($tenantId, $label, $email, $tenantOverrides, $subscriptionOverrides) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(array_merge([
            'name' => "{$label} Co",
            'type' => 'customer',
            'plan' => 'standard',
            'status' => 'active',
            'billing_interval' => 'monthly',
        ], $tenantOverrides));
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => $email,
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        $subscription = new Subscription(array_merge([
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_'.Str::random(14),
            'status' => Subscription::STATUS_ACTIVE,
            'renews_at' => now()->addDays(10),
            'ends_at' => null,
        ], $subscriptionOverrides));
        $subscription->tenant_id = $tenantId;
        $subscription->save();

        return [$tenantId, $email, $subscription];
    });
}

function subscriptionFresh(string $tenantId, int $subscriptionId): Subscription
{
    return DB::transaction(function () use ($tenantId, $subscriptionId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Subscription::query()->find($subscriptionId);
    });
}

test('an auto-renewing subscription exactly 10 days from renews_at gets the courtesy reminder, exactly once', function () {
    [$tenantId, $email, $subscription] = seedActiveSubscription('Courtesy Ten Day', subscriptionOverrides: [
        'renews_at' => today()->addDays(10)->setTime(14, 0),
    ]);

    Notification::fake();

    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    $owner = User::withoutGlobalScopes()->where('email', $email)->first();

    // QA-audit fix (Finding 1): dispatched via Notification::route('mail',
    // ...) now, never $owner->notify(...) — see
    // App\Console\Commands\SendRenewalReminders's own docblock at the
    // call site, and App\Notifications\VerifyEmailAddress's for the full
    // root cause. assertSentOnDemand (not assertSentTo($owner, ...)) is
    // the correct assertion for an ad-hoc-routed notification.
    Notification::assertSentOnDemandTimes(SubscriptionRenewalReminder::class, 1);
    Notification::assertSentOnDemand(
        SubscriptionRenewalReminder::class,
        function ($notification, $channels, $notifiable) use ($owner) {
            expect($notifiable->routes['mail'])->toBe($owner->email);

            $mail = $notification->toMail($owner);

            expect($mail->subject)->toContain('renews in 10 days');
            expect(implode(' ', $mail->introLines))->toContain('Your card will be charged $20')
                ->toContain('for your monthly plan');

            return true;
        }
    );

    $fresh = subscriptionFresh($tenantId, $subscription->id);
    expect($fresh->renewal_reminder_10d_sent_for)->not->toBeNull();
    expect($fresh->renewal_reminder_10d_sent_for->isSameDay(today()->addDays(10)))->toBeTrue();
    expect($fresh->renewal_reminder_5d_sent_for)->toBeNull();
});

test('a cancel-at-period-end subscription exactly 5 days from ends_at gets the action-needed reminder, exactly once', function () {
    [$tenantId, $email, $subscription] = seedActiveSubscription('Action Needed Five Day', subscriptionOverrides: [
        'status' => Subscription::STATUS_CANCELED,
        'renews_at' => null,
        'ends_at' => today()->addDays(5)->setTime(9, 30),
    ]);

    Notification::fake();

    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    $owner = User::withoutGlobalScopes()->where('email', $email)->first();

    // QA-audit fix (Finding 1): see the previous test's own comment.
    Notification::assertSentOnDemandTimes(SubscriptionRenewalReminder::class, 1);
    Notification::assertSentOnDemand(
        SubscriptionRenewalReminder::class,
        function ($notification, $channels, $notifiable) use ($owner) {
            expect($notifiable->routes['mail'])->toBe($owner->email);

            $mail = $notification->toMail($owner);

            expect($mail->subject)->toContain('ends in 5 days');
            expect(implode(' ', $mail->introLines))
                ->toContain('Your subscription ends on')
                ->toContain('renew now to keep sending review requests');

            return true;
        }
    );

    $fresh = subscriptionFresh($tenantId, $subscription->id);
    expect($fresh->renewal_reminder_5d_sent_for)->not->toBeNull();
    expect($fresh->renewal_reminder_10d_sent_for)->toBeNull();
});

test('a subscription outside the 10/5-day window is left alone', function () {
    seedActiveSubscription('Outside Window', subscriptionOverrides: [
        'renews_at' => today()->addDays(9),
    ]);
    seedActiveSubscription('Outside Window Other Side', subscriptionOverrides: [
        'renews_at' => today()->addDays(11),
    ]);

    Notification::fake();

    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('re-running the command the same day does not duplicate an already-sent reminder', function () {
    [$tenantId, $email, $subscription] = seedActiveSubscription('No Duplicate Same Day', subscriptionOverrides: [
        'renews_at' => today()->addDays(5),
    ]);

    Notification::fake();

    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();
    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    Notification::assertSentOnDemandTimes(SubscriptionRenewalReminder::class, 1);
});

test('a subscription that already got its 10-day reminder still gets the 5-day one once that day arrives', function () {
    [$tenantId, $email, $subscription] = seedActiveSubscription('Both Thresholds', subscriptionOverrides: [
        'renews_at' => today()->addDays(10),
    ]);

    Notification::fake();
    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    Notification::assertSentOnDemandTimes(SubscriptionRenewalReminder::class, 1);

    // 5 days pass — same subscription, same renews_at, now exactly 5 days
    // out instead of 10.
    $this->travelTo(now()->addDays(5));

    Notification::fake();
    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    Notification::assertSentOnDemandTimes(SubscriptionRenewalReminder::class, 1);

    $fresh = subscriptionFresh($tenantId, $subscription->id);
    expect($fresh->renewal_reminder_10d_sent_for)->not->toBeNull();
    expect($fresh->renewal_reminder_5d_sent_for)->not->toBeNull();

    $this->travelBack();
});

test('a past_due subscription (neither status the command watches) sends nothing', function () {
    seedActiveSubscription('Past Due Untouched', subscriptionOverrides: [
        'status' => Subscription::STATUS_PAST_DUE,
        'renews_at' => today()->addDays(10),
    ]);

    Notification::fake();

    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('TenantController\'s renewal_reminder banner field reflects the same live subscription state', function () {
    [, , $subscription] = seedActiveSubscription('Banner Live State', subscriptionOverrides: [
        'renews_at' => today()->addDays(7),
    ]);

    $token = DB::transaction(function () use ($subscription) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $subscription->tenant_id]);
        markEmailVerified($subscription->billable_id, $subscription->tenant_id);
        $user = User::query()->find($subscription->billable_id);

        return $user->createToken('test')->plainTextToken;
    });

    $response = test()->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');

    $response->assertOk();
    expect($response->json('data.renewal_reminder.days_until'))->toBe(7);
    expect($response->json('data.renewal_reminder.auto_renew'))->toBeTrue();
    expect($response->json('data.renewal_reminder.billing_interval'))->toBe('monthly');
    expect($response->json('data.renewal_reminder.amount_display'))->toBe('$20');
});
