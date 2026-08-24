<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The permissions gate itself (RequirePermission/EnsureTenantOwner
 * middleware) — separate from TeamInviteTest.php, which covers how a
 * member comes to exist in the first place. Every test here uses
 * seedTeamMember() to skip straight to "a member with these permissions
 * already exists," per that helper's own docblock.
 */
test('CRITICAL: a member with only reviews access is rejected, not just hidden in the UI, when hitting the templates endpoint directly', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Reviews Only Owner');
    [$memberToken] = seedTeamMember($tenantId, 'Reviews Only Member', ['reviews' => true]);

    $response = $this->withHeader('Authorization', "Bearer {$memberToken}")->getJson('/api/v1/templates');

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('permission_denied');

    // The same member's actually-granted resource still works — this
    // isn't a blanket lockout, only the ungranted resource is blocked.
    $this->withHeader('Authorization', "Bearer {$memberToken}")->getJson('/api/v1/reviews')->assertOk();
});

test('a member with no permissions at all is rejected on every one of the four gated resources', function () {
    [, $tenantId] = seedCustomerAccount('No Permissions Owner');
    [$memberToken] = seedTeamMember($tenantId, 'No Permissions Member');

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$memberToken}");

    $auth()->getJson('/api/v1/contacts')->assertStatus(403);
    $auth()->getJson('/api/v1/templates')->assertStatus(403);
    $auth()->getJson('/api/v1/reviews')->assertStatus(403);
    $auth()->getJson('/api/v1/analytics/campaign')->assertStatus(403);
});

test('a member granted all four resource permissions can reach all four', function () {
    [, $tenantId] = seedCustomerAccount('All Permissions Owner');
    [$memberToken] = seedTeamMember($tenantId, 'All Permissions Member', [
        'contacts' => true, 'templates' => true, 'reviews' => true, 'analytics' => true,
    ]);

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$memberToken}");

    $auth()->getJson('/api/v1/contacts')->assertOk();
    $auth()->getJson('/api/v1/templates')->assertOk();
    $auth()->getJson('/api/v1/reviews')->assertOk();
    $auth()->getJson('/api/v1/analytics/campaign')->assertOk();
});

test('an owner reaches every gated resource regardless of the permissions column', function () {
    [$ownerToken] = seedCustomerAccount('Owner Bypass');

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$ownerToken}");

    $auth()->getJson('/api/v1/contacts')->assertOk();
    $auth()->getJson('/api/v1/templates')->assertOk();
    $auth()->getJson('/api/v1/reviews')->assertOk();
    $auth()->getJson('/api/v1/analytics/campaign')->assertOk();
});

test('CRITICAL: granting a permission takes effect on the member\'s very next request, not just after re-login', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Grant Immediate Owner');
    [$memberToken, $memberId] = seedTeamMember($tenantId, 'Grant Immediate Member');

    // Blocked before the grant.
    $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->getJson('/api/v1/templates')
        ->assertStatus(403);

    // The owner grants it — the member's token is never touched, no
    // logout/login happens anywhere in this test.
    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->patchJson("/api/v1/team/members/{$memberId}/permissions", ['permissions' => ['templates' => true]])
        ->assertOk();

    // The exact same still-valid token now succeeds, immediately.
    $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->getJson('/api/v1/templates')
        ->assertOk();
});

test('CRITICAL: revoking a permission takes effect on the member\'s very next request, not just after re-login', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Revoke Immediate Owner');
    [$memberToken, $memberId] = seedTeamMember($tenantId, 'Revoke Immediate Member', ['templates' => true]);

    $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->getJson('/api/v1/templates')
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->patchJson("/api/v1/team/members/{$memberId}/permissions", ['permissions' => ['templates' => false]])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->getJson('/api/v1/templates')
        ->assertStatus(403);
});

test('CRITICAL: a member cannot reach team management under any permission combination, including all four resources granted', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('No Team Access Owner');
    [$memberToken, $memberId] = seedTeamMember($tenantId, 'No Team Access Member', [
        'contacts' => true, 'templates' => true, 'reviews' => true, 'analytics' => true,
    ]);

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$memberToken}");

    $teamIndex = $auth()->getJson('/api/v1/team');
    $teamIndex->assertStatus(403);
    expect($teamIndex->json('error'))->toBe('owner_only');

    $auth()->postJson('/api/v1/team/invite', ['email' => 'someone@example.com'])->assertStatus(403);
    $auth()->patchJson("/api/v1/team/members/{$memberId}/permissions", ['permissions' => ['contacts' => true]])
        ->assertStatus(403);

    // Confirm from the owner's own side that nothing changed — the
    // member's blocked attempt above wasn't silently partially applied.
    $status = $this->withHeader('Authorization', "Bearer {$ownerToken}")->getJson('/api/v1/team');
    $status->assertOk();
    expect($status->json('data.members'))->toHaveCount(2);
});

test('CRITICAL: a member cannot reach billing under any permission combination, including all four resources granted', function () {
    [, $tenantId] = seedCustomerAccount('No Billing Access Owner');
    [$memberToken] = seedTeamMember($tenantId, 'No Billing Access Member', [
        'contacts' => true, 'templates' => true, 'reviews' => true, 'analytics' => true,
    ]);

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$memberToken}");

    $subscribe = $auth()->postJson('/api/v1/subscribe', ['interval' => 'monthly']);
    $subscribe->assertStatus(403);
    expect($subscribe->json('error'))->toBe('owner_only');

    $auth()->getJson('/api/v1/subscription')->assertStatus(403);

    // PATCH /subscription (auto-renew toggle) and GET /subscription/portal
    // (Paddle's customer portal) don't exist yet (checkout + webhook
    // handling only, per this round's scope) — extend this test with
    // those two assertions once they're rebuilt; billing stays
    // owner-only for them too, same reasoning as above.
});

test('issuing a webhook API key is gated by the contacts permission — closes the bypass a member could otherwise use to create contacts through the webhook API', function () {
    [, $tenantId] = seedCustomerAccount('Api Key Gate Owner');
    [$memberToken] = seedTeamMember($tenantId, 'Api Key Gate Member');

    $this->withHeader('Authorization', "Bearer {$memberToken}")
        ->postJson('/api/v1/api-keys')
        ->assertStatus(403);

    [$grantedToken] = seedTeamMember($tenantId, 'Api Key Gate Granted Member', ['contacts' => true]);

    $this->withHeader('Authorization', "Bearer {$grantedToken}")
        ->postJson('/api/v1/api-keys')
        ->assertCreated();
});

test('an owner cannot have their own permissions edited through the update endpoint', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Self Edit Owner');

    $ownerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->where('role', 'owner')->first()->id;
    });

    $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->patchJson("/api/v1/team/members/{$ownerId}/permissions", ['permissions' => ['contacts' => true]]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('owner_only');
});

test('updating permissions rejects an unknown permission key — never lets a client smuggle in billing or team', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Unknown Key Owner');
    [, $memberId] = seedTeamMember($tenantId, 'Unknown Key Member');

    $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->patchJson("/api/v1/team/members/{$memberId}/permissions", ['permissions' => ['billing' => true]]);

    $response->assertStatus(422);
    expect($response->json('fields.permissions'))->not->toBeNull();
});

test('CRITICAL: an owner in tenant A cannot edit a member\'s permissions in tenant B', function () {
    [$ownerAToken] = seedCustomerAccount('Cross Tenant Owner A');
    [, $tenantB] = seedCustomerAccount('Cross Tenant Owner B');
    [, $memberBId] = seedTeamMember($tenantB, 'Cross Tenant Member B');

    $response = $this->withHeader('Authorization', "Bearer {$ownerAToken}")
        ->patchJson("/api/v1/team/members/{$memberBId}/permissions", ['permissions' => ['contacts' => true]]);

    $response->assertStatus(404);
});
