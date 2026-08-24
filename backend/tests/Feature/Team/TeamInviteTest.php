<?php

use App\Models\TeamInvite;
use App\Models\User;
use App\Notifications\TeamInviteReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The owner-invites-a-teammate flow end to end: creating the invite,
 * looking it up pre-auth, accepting it (which creates the actual member
 * User — see TeamPermissionsTest.php for what that member can and can't
 * do once they exist).
 */
function inviteForTenant(string $tenantId): TeamInvite
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return TeamInvite::query()->latest('id')->first();
    });
}

test('inviting requires authentication', function () {
    $this->postJson('/api/v1/team/invite', ['email' => 'someone@example.com'])->assertUnauthorized();
});

test('an owner invites a new teammate — creates a pending invite and emails exactly that address', function () {
    [$ownerToken] = seedCustomerAccount('Invite Happy Path');
    Notification::fake();

    $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'newmember@example.com']);

    $response->assertCreated();
    expect($response->json('data.email'))->toBe('newmember@example.com');

    Notification::assertSentOnDemand(
        TeamInviteReceived::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'newmember@example.com'
    );
});

test('inviting an email that is already on the team is rejected', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Invite Already Member');
    seedTeamMember($tenantId, 'Existing Member');

    // Re-derive the exact email seedTeamMember just created, rather than
    // hardcoding a duplicate literal that could silently drift from it.
    $existingEmail = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->where('role', 'member')->value('email');
    });

    $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => $existingEmail]);

    $response->assertStatus(422);
    expect($response->json('fields.email'))->not->toBeNull();
});

test('inviting an email with an already-pending invite is rejected', function () {
    [$ownerToken] = seedCustomerAccount('Invite Duplicate Pending');

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'dup@example.com'])
        ->assertCreated();

    $second = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'dup@example.com']);

    $second->assertStatus(422);
    expect($second->json('fields.email'))->not->toBeNull();
});

test('the public invite-lookup endpoint returns the tenant name and email for a valid pending invite', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Invite Public Lookup');
    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'lookup@example.com'])
        ->assertCreated();

    $invite = inviteForTenant($tenantId);

    $response = $this->getJson("/api/v1/team/invite/{$invite->token}");

    $response->assertOk();
    expect($response->json('data.email'))->toBe('lookup@example.com');
    expect($response->json('data.tenant_name'))->toBe('Invite Public Lookup Co');
});

test('the public invite-lookup endpoint 404s cleanly for an unknown token', function () {
    $response = $this->getJson('/api/v1/team/invite/'.str_repeat('a', 48));

    $response->assertStatus(404);
    expect($response->json('error'))->toBe('invite_invalid');
});

test('CRITICAL: accepting an invite creates a member on the inviting tenant, never the accepter\'s own new tenant', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Accept Invite Owner');
    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'accepted@example.com'])
        ->assertCreated();

    $invite = inviteForTenant($tenantId);

    $response = $this->postJson("/api/v1/team/invite/{$invite->token}/accept", [
        'name' => 'Accepted Member',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    $response->assertCreated();
    expect($response->json('data.user.role'))->toBe('member');
    expect($response->json('data.user.email'))->toBe('accepted@example.com');
    expect($response->json('data.tenant.id'))->toBe($tenantId);
    expect($response->json('data.token'))->not->toBeEmpty();

    // The new token actually works and is scoped to the right tenant —
    // real evidence, not just trusting the accept response's own claims.
    $memberToken = $response->json('data.token');
    $whoAmI = $this->withHeader('Authorization', "Bearer {$memberToken}")->getJson('/api/v1/tenant');
    $whoAmI->assertOk();
    expect($whoAmI->json('data.id'))->toBe($tenantId);
    expect($whoAmI->json('data.role'))->toBe('member');
    expect($whoAmI->json('data.is_owner'))->toBeFalse();
    // Zero access by default — the owner has to grant it afterward.
    expect($whoAmI->json('data.permissions'))->toBe([
        'contacts' => false, 'templates' => false, 'reviews' => false, 'analytics' => false,
    ]);

    $accepted = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return TeamInvite::query()->latest('id')->first()->accepted_at;
    });
    expect($accepted)->not->toBeNull();
});

test('an invite can only be accepted once', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Accept Invite Twice');
    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'onceonly@example.com'])
        ->assertCreated();

    $invite = inviteForTenant($tenantId);
    $body = [
        'name' => 'Once Only',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ];

    $this->postJson("/api/v1/team/invite/{$invite->token}/accept", $body)->assertCreated();

    $second = $this->postJson("/api/v1/team/invite/{$invite->token}/accept", $body);
    $second->assertStatus(404);
    expect($second->json('error'))->toBe('invite_invalid');
});

test('an owner revokes a pending invite — it disappears from the pending list and can no longer be accepted', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Revoke Invite Owner');
    $invited = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'revoked@example.com'])
        ->assertCreated();

    $inviteId = $invited->json('data.id');
    $invite = inviteForTenant($tenantId);

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->deleteJson("/api/v1/team/invites/{$inviteId}")
        ->assertOk();

    $team = $this->withHeader('Authorization', "Bearer {$ownerToken}")->getJson('/api/v1/team');
    expect($team->json('data.pending_invites'))->toHaveCount(0);

    $lookup = $this->getJson("/api/v1/team/invite/{$invite->token}");
    $lookup->assertStatus(404);
});

test('GET /team lists both existing members and pending invites', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Team List Owner');
    seedTeamMember($tenantId, 'Team List Member', ['contacts' => true]);
    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'pending@example.com'])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")->getJson('/api/v1/team');

    $response->assertOk();
    expect($response->json('data.members'))->toHaveCount(2);
    expect($response->json('data.pending_invites'))->toHaveCount(1);
    expect($response->json('data.pending_invites.0.email'))->toBe('pending@example.com');

    $member = collect($response->json('data.members'))->firstWhere('role', 'member');
    expect($member['permissions'])->toBe([
        'contacts' => true, 'templates' => false, 'reviews' => false, 'analytics' => false,
    ]);

    $owner = collect($response->json('data.members'))->firstWhere('role', 'owner');
    expect($owner['permissions'])->toBe([
        'contacts' => true, 'templates' => true, 'reviews' => true, 'analytics' => true,
    ]);
});

test('CRITICAL: an owner in tenant A cannot revoke an invite belonging to tenant B', function () {
    [$ownerAToken] = seedCustomerAccount('Cross Tenant Revoke A');
    [$ownerBToken] = seedCustomerAccount('Cross Tenant Revoke B');
    $invited = $this->withHeader('Authorization', "Bearer {$ownerBToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'tenant-b-invite@example.com'])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$ownerAToken}")
        ->deleteJson("/api/v1/team/invites/{$invited->json('data.id')}");

    $response->assertStatus(404);
});

test('a member cannot invite a teammate — inviting is owner-only', function () {
    [, $tenantId] = seedCustomerAccount('Member Cannot Invite Owner');
    [$memberToken] = seedTeamMember($tenantId, 'Member Cannot Invite', ['contacts' => true, 'templates' => true, 'reviews' => true, 'analytics' => true]);

    $response = $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->postJson('/api/v1/team/invite', ['email' => 'shouldnotwork@example.com']);

    $response->assertStatus(403);
});
