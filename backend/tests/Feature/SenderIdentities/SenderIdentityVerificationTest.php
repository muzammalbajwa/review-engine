<?php

use App\Models\SenderIdentity;
use App\Notifications\VerifySenderIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * .claude/CLAUDE.md Phase 2 Step 2: sender-identity verification — a
 * message must never be sendable from an address the tenant hasn't
 * proven they control.
 */
function registerAndGetTokenForSender(string $label): array
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

function senderIdentitiesForTenant(string $tenantId): Collection
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return SenderIdentity::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
    });
}

test('creating a sender identity requires authentication', function () {
    $this->postJson('/api/v1/sender-identities', ['from_name' => 'Acme', 'from_email' => 'reviews@acme.test'])
        ->assertUnauthorized();
});

test('creating a sender identity persists it unverified and emails exactly the claimed address', function () {
    [$token, $tenantId] = registerAndGetTokenForSender('Create Identity');
    Notification::fake();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sender-identities', ['from_name' => 'Acme Roofing', 'from_email' => 'reviews@acme.test']);

    $response->assertCreated();
    expect($response->json('data.verified'))->toBeFalse();

    $identities = senderIdentitiesForTenant($tenantId);
    expect($identities)->toHaveCount(1);
    expect($identities->first()->verified)->toBeFalse();

    Notification::assertSentOnDemand(
        VerifySenderIdentity::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'reviews@acme.test'
    );
});

test('visiting the signed verification link marks the identity verified', function () {
    [$token, $tenantId] = registerAndGetTokenForSender('Verify Link');

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sender-identities', ['from_name' => 'Acme Roofing', 'from_email' => 'reviews@acme.test'])
        ->assertCreated();
    $senderId = $create->json('data.id');

    $verifyUrl = URL::temporarySignedRoute(
        'sender-identities.verify',
        now()->addDays(2),
        ['tenant' => $tenantId, 'sender' => $senderId],
    );

    $response = $this->getJson($verifyUrl);

    $response->assertOk();
    expect($response->json('data.sender_identity.verified'))->toBeTrue();

    $identity = senderIdentitiesForTenant($tenantId)->first();
    expect($identity->verified)->toBeTrue();
});

test('a tampered verification link is rejected and the identity stays unverified', function () {
    [$token, $tenantId] = registerAndGetTokenForSender('Tampered Link');

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sender-identities', ['from_name' => 'Acme Roofing', 'from_email' => 'reviews@acme.test'])
        ->assertCreated();
    $senderId = $create->json('data.id');

    $verifyUrl = URL::temporarySignedRoute(
        'sender-identities.verify',
        now()->addDays(2),
        ['tenant' => $tenantId, 'sender' => $senderId],
    );

    // Swap in a different (real, existing) tenant id — the signature was
    // computed over the original, so this must invalidate it outright.
    [, $otherTenantId] = registerAndGetTokenForSender('Tampered Link Other');
    $tamperedUrl = str_replace($tenantId, $otherTenantId, $verifyUrl);

    $response = $this->getJson($tamperedUrl);

    $response->assertStatus(403);

    $identity = senderIdentitiesForTenant($tenantId)->first();
    expect($identity->verified)->toBeFalse();
});

test('an expired verification link is rejected', function () {
    [$token, $tenantId] = registerAndGetTokenForSender('Expired Link');

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sender-identities', ['from_name' => 'Acme Roofing', 'from_email' => 'reviews@acme.test'])
        ->assertCreated();
    $senderId = $create->json('data.id');

    $verifyUrl = URL::temporarySignedRoute(
        'sender-identities.verify',
        now()->addDays(2),
        ['tenant' => $tenantId, 'sender' => $senderId],
    );

    Carbon::setTestNow(now()->addDays(3));
    $response = $this->getJson($verifyUrl);
    Carbon::setTestNow();

    $response->assertStatus(403);

    $identity = senderIdentitiesForTenant($tenantId)->first();
    expect($identity->verified)->toBeFalse();
});

test('a tenant only ever sees its own sender identities', function () {
    [$tokenA] = registerAndGetTokenForSender('Sender Isolation A');
    [$tokenB] = registerAndGetTokenForSender('Sender Isolation B');

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/sender-identities', ['from_name' => 'A Co', 'from_email' => 'a@a-co.test'])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/sender-identities');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});
