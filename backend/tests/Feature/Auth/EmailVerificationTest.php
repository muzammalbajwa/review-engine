<?php

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The "add email verification" decision doc. Laravel's own built-in
 * MustVerifyEmail mechanism (App\Models\User implements it), adapted for
 * this app's Sanctum-token-only, no-session API — same "real signed URL,
 * clicked from an email, carries no bearer token" pattern
 * SenderIdentityVerificationTest.php already proves for sender identities,
 * reused here for User's own verification.verify route.
 */
function verifyUrlFor(int $userId, string $email): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $userId,
        'hash' => sha1($email),
    ]);
}

function isVerifiedInDb(int $userId, string $tenantId): bool
{
    return DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId)?->email_verified_at !== null;
    });
}

test('registering sends a real, queued verification email to the new owner and returns email_verified: false', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/register', [
        'name' => 'Verify Owner',
        'business_name' => 'Verify Owner Co',
        'email' => 'verifyowner-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    $response->assertCreated();
    expect($response->json('data.user.email_verified'))->toBeFalse();

    $userId = $response->json('data.user.id');
    $tenantId = $response->json('data.tenant.id');

    $owner = DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId);
    });

    // QA-audit fix (Finding 1): dispatched via Notification::route('mail',
    // ...) now, never $user->notify(...) — see App\Notifications\
    // VerifyEmailAddress's own docblock for why. assertSentOnDemand (not
    // assertSentTo($owner, ...)) is the correct assertion for an
    // ad-hoc-routed notification; the callback confirms it was routed to
    // this exact owner's email, not just "some" VerifyEmailAddress.
    Notification::assertSentOnDemand(
        VerifyEmailAddress::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $owner->email
    );
    expect(isVerifiedInDb($userId, $tenantId))->toBeFalse();
});

test('an unverified account can log in immediately — email verification never blocks login', function () {
    [$token, $tenantId, $userId] = seedUnverifiedCustomerAccount('Login While Unverified');
    expect($token)->toBeString();

    $email = DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId)->email;
    });

    $login = $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'correct-horse-battery-staple']);

    $login->assertOk();
    expect($login->json('data.user.email_verified'))->toBeFalse();
});

test('an unverified account keeps full read access — dashboard, contacts, templates, reviews, analytics, tenant', function () {
    [$token] = seedUnverifiedCustomerAccount('Unverified Read Access');
    $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

    $auth()->getJson('/api/v1/tenant')->assertOk();
    $auth()->getJson('/api/v1/contacts')->assertOk();
    $auth()->getJson('/api/v1/templates')->assertOk();
    $auth()->getJson('/api/v1/reviews')->assertOk();
    $auth()->getJson('/api/v1/analytics/campaign')->assertOk();
});

test('an unverified account cannot use authenticated quick-add — clear email_unverified message, not a silent block', function () {
    [$token] = seedUnverifiedCustomerAccount('Unverified Quick Add');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Blocked', 'phone' => '555-0100']);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('email_unverified');
    expect($response->json('message'))->toContain('Verify your email');
});

test('an unverified account cannot import a CSV of new contacts', function () {
    [$token] = seedUnverifiedCustomerAccount('Unverified CSV Import');

    $csv = "Name,Phone\nBlocked Contact,555-0100\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import', [
            'file' => $file,
            'mapping' => ['name' => 'Name', 'phone' => 'Phone'],
        ]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('email_unverified');
});

test('an unverified account\'s webhook API key can not create contacts', function () {
    [$token] = seedUnverifiedCustomerAccount('Unverified Webhook API');

    $apiKey = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/api-keys')
        ->assertCreated()
        ->json('data.token');

    $response = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Blocked Webhook Contact', 'phone' => '555-0100']);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('email_unverified');
});

test('an unverified account\'s own public guest quick-add link is also blocked', function () {
    [$token, $tenantId] = seedUnverifiedCustomerAccount('Unverified Guest Quick Add');

    $quickToken = DB::transaction(function () use ($tenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return Tenant::withoutGlobalScopes()->find($tenantId)->quick_add_token;
    });

    $response = $this->postJson("/api/v1/quick/{$quickToken}", ['name' => 'Blocked Guest', 'phone' => '555-0100']);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('email_unverified');

    // Read access (confirming the link itself resolves) still works.
    $this->getJson("/api/v1/quick/{$quickToken}")->assertOk();
});

test('CRITICAL: clicking the real signed verification link unblocks sending immediately, using the same original token — no re-login', function () {
    [$token, $tenantId, $userId] = seedUnverifiedCustomerAccount('Real Link Unblocks');

    $email = DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId)->email;
    });

    // Confirmed blocked beforehand, with the exact token we'll reuse.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Still Blocked', 'phone' => '555-0100'])
        ->assertStatus(422);

    $verifyResponse = $this->getJson(verifyUrlFor($userId, $email));
    // A redirect (to the frontend), not JSON — a real person clicks this
    // from their inbox.
    expect($verifyResponse->status())->toBeIn([301, 302, 303, 307, 308]);
    expect($verifyResponse->headers->get('Location'))->toContain('/settings?verified=1');

    expect(isVerifiedInDb($userId, $tenantId))->toBeTrue();

    // The exact same Bearer token from before registration/verification —
    // never re-issued, never re-logged-in.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Now Allowed', 'phone' => '555-0199'])
        ->assertCreated();
});

test('a tampered verification link (wrong id) is rejected and the account stays unverified', function () {
    [, $tenantId, $userId] = seedUnverifiedCustomerAccount('Tampered Link');
    [, , $otherUserId] = seedUnverifiedCustomerAccount('Tampered Link Other');

    $email = DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId)->email;
    });

    $verifyUrl = verifyUrlFor($userId, $email);
    $tamperedUrl = str_replace("/{$userId}/", "/{$otherUserId}/", $verifyUrl);

    $response = $this->getJson($tamperedUrl);

    $response->assertStatus(403);
    expect(isVerifiedInDb($userId, $tenantId))->toBeFalse();
});

test('an expired verification link is rejected', function () {
    [, $tenantId, $userId] = seedUnverifiedCustomerAccount('Expired Link');

    $email = DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId)->email;
    });

    $verifyUrl = verifyUrlFor($userId, $email);

    Carbon::setTestNow(now()->addMinutes(61));
    $response = $this->getJson($verifyUrl);
    Carbon::setTestNow();

    $response->assertStatus(403);
    expect(isVerifiedInDb($userId, $tenantId))->toBeFalse();
});

test('the resend endpoint requires authentication', function () {
    $this->postJson('/api/v1/email/verification-notification')->assertUnauthorized();
});

test('resending the verification email while unverified sends another real notification', function () {
    [$token, $tenantId, $userId] = seedUnverifiedCustomerAccount('Resend Unverified');

    Notification::fake();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/email/verification-notification');

    $response->assertOk();
    expect($response->json('data.message'))->toBe('Verification email sent.');

    $owner = DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId);
    });

    // QA-audit fix (Finding 1): ad-hoc-routed now — see the assertion
    // above's own comment. Only this one account is touched in this
    // test, so counting all on-demand sends of this class is equivalent
    // to the original assertSentToTimes($owner, ...) intent.
    Notification::assertSentOnDemandTimes(VerifyEmailAddress::class, 1);
    Notification::assertSentOnDemand(
        VerifyEmailAddress::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $owner->email
    );
});

test('resending once already verified is a harmless no-op — no second email', function () {
    [$token, $tenantId, $userId] = seedUnverifiedCustomerAccount('Resend Already Verified');
    markEmailVerified($userId, $tenantId);

    Notification::fake();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/email/verification-notification');

    $response->assertOk();
    expect($response->json('data.message'))->toBe('Your email is already verified.');

    Notification::assertNothingSent();
});

test('GET /tenant reports sending_blocked_reason: email_unverified for an unverified tenant, and null once verified', function () {
    [$token, $tenantId, $userId] = seedUnverifiedCustomerAccount('Tenant Payload Reason');

    $before = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');
    $before->assertOk();
    expect($before->json('data.sending_blocked_reason'))->toBe('email_unverified');
    expect($before->json('data.email_verified'))->toBeFalse();

    markEmailVerified($userId, $tenantId);

    $after = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');
    $after->assertOk();
    expect($after->json('data.sending_blocked_reason'))->toBeNull();
    expect($after->json('data.email_verified'))->toBeTrue();
});

test('email verification is owner-scoped: a team member is blocked while the owner is unverified, and unblocked once the owner verifies — the member\'s own verification is irrelevant', function () {
    [$ownerToken, $tenantId, $ownerId] = seedUnverifiedCustomerAccount('Owner Scoped Gate');
    [$memberToken, $memberId] = seedTeamMember($tenantId, 'Owner Scoped Member', ['contacts' => true]);

    // The member's OWN email is verified, but sending is still blocked —
    // it's the owner's verification that gates the tenant.
    markEmailVerified($memberId, $tenantId);

    $blocked = $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Still Blocked By Owner', 'phone' => '555-0100']);
    $blocked->assertStatus(422);
    expect($blocked->json('error'))->toBe('email_unverified');

    markEmailVerified($ownerId, $tenantId);

    $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Now Allowed', 'phone' => '555-0199'])
        ->assertCreated();
});
