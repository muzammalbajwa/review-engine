<?php

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Notifications\WelcomeEmail;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Verification, not registration, is the "real account" moment WelcomeEmail
 * ties to (AuthController::verifyEmail()'s own docblock). Reuses
 * verifyUrlFor()/isVerifiedInDb() (EmailVerificationTest.php — all Pest
 * files in this suite share one process, see that file's own docblock).
 */
function ownerFor(int $userId, string $tenantId): User
{
    return DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId);
    });
}

function markOnboardingComplete(string $tenantId): void
{
    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        Tenant::query()->find($tenantId)?->forceFill(['onboarding_completed_at' => now()])->save();
    });
}

test('registering does NOT send the welcome email — only verification does', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/register', [
        'name' => 'No Welcome Yet',
        'business_name' => 'No Welcome Yet Co',
        'email' => 'nowelcomeyet-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);
    $response->assertCreated();

    $owner = ownerFor($response->json('data.user.id'), $response->json('data.tenant.id'));

    // QA-audit fix (Finding 1): both notifications are dispatched via
    // Notification::route('mail', ...) now, never $user->notify(...) —
    // see App\Notifications\VerifyEmailAddress's own docblock for why.
    // assertSentOnDemand/assertNotSentTo(new AnonymousNotifiable, ...)
    // are the correct assertions for an ad-hoc-routed notification.
    Notification::assertSentOnDemand(
        VerifyEmailAddress::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $owner->email
    );
    Notification::assertNotSentTo(new AnonymousNotifiable, WelcomeEmail::class);
});

test('verifying an account with onboarding NOT yet completed sends a welcome email pointing to /onboarding', function () {
    [, $tenantId, $userId] = seedUnverifiedCustomerAccount('Welcome Onboarding Pending');
    $owner = ownerFor($userId, $tenantId);

    Notification::fake();
    $this->getJson(verifyUrlFor($userId, $owner->email))->assertRedirect();

    $owner = ownerFor($userId, $tenantId);
    // QA-audit fix (Finding 1): ad-hoc-routed now — see the earlier test
    // in this file for why assertSentOnDemand replaces assertSentTo.
    Notification::assertSentOnDemandTimes(WelcomeEmail::class, 1);
    Notification::assertSentOnDemand(
        WelcomeEmail::class,
        function ($notification, $channels, $notifiable) use ($owner) {
            expect($notifiable->routes['mail'])->toBe($owner->email);

            $mail = $notification->toMail($owner);

            expect($mail->subject)->toContain("You're verified");
            expect(implode(' ', $mail->introLines))->toContain('is ready to start collecting reviews');
            expect($mail->actionText)->toBe('Finish setup');
            expect($mail->actionUrl)->toContain('/onboarding');

            return true;
        }
    );
});

test('verifying an account with onboarding already completed sends a welcome email pointing to /dashboard, not /onboarding', function () {
    [, $tenantId, $userId] = seedUnverifiedCustomerAccount('Welcome Onboarding Done');
    markOnboardingComplete($tenantId);
    $owner = ownerFor($userId, $tenantId);

    Notification::fake();
    $this->getJson(verifyUrlFor($userId, $owner->email))->assertRedirect();

    $owner = ownerFor($userId, $tenantId);
    // QA-audit fix (Finding 1): ad-hoc-routed now — see the first test in
    // this file for why assertSentOnDemand replaces assertSentTo.
    Notification::assertSentOnDemandTimes(WelcomeEmail::class, 1);
    Notification::assertSentOnDemand(
        WelcomeEmail::class,
        function ($notification, $channels, $notifiable) use ($owner) {
            expect($notifiable->routes['mail'])->toBe($owner->email);

            $mail = $notification->toMail($owner);

            expect($mail->actionText)->toBe('Go to dashboard');
            expect($mail->actionUrl)->toContain('/dashboard');

            return true;
        }
    );
});

test('re-visiting an already-used verification link never sends a second welcome email', function () {
    [, $tenantId, $userId] = seedUnverifiedCustomerAccount('Welcome No Duplicate');
    $owner = ownerFor($userId, $tenantId);
    $url = verifyUrlFor($userId, $owner->email);

    $this->getJson($url)->assertRedirect();
    expect(isVerifiedInDb($userId, $tenantId))->toBeTrue();

    Notification::fake();
    $this->getJson($url)->assertRedirect();

    Notification::assertNothingSent();
});

test('the welcome email — and every other MailMessage notification — renders through the reviewengine brand theme, not Laravel\'s stock default', function () {
    expect(config('mail.markdown.theme'))->toBe('reviewengine');

    $owner = new User(['name' => 'Theme Check', 'email' => 'theme-check@example.com']);
    $owner->id = 1;

    $welcome = new WelcomeEmail(tenantName: 'Theme Check Co', onboardingCompleted: false);
    // QA-audit fix (Finding 1): VerifyEmailAddress now takes the user id +
    // email as plain scalars (never rehydrates $notifiable) — see its own
    // docblock. $owner here is just a local, unsaved stub either way.
    $verify = new VerifyEmailAddress($owner->id, $owner->email);

    foreach ([$welcome, $verify] as $notification) {
        $mail = $notification->toMail($owner);
        $html = (string) app(Markdown::class)
            ->theme($mail->theme ?? config('mail.markdown.theme'))
            ->render($mail->markdown ?? 'mail::message', array_merge($mail->data(), ['level' => $mail->level]));

        // The app's own DESIGN.md palette (--primary moss green, the
        // action button's color) — not Laravel's stock #18181b/#2563eb.
        expect($html)->toContain('#2F6F4E');
        expect($html)->not->toContain('#18181b');
    }
});
