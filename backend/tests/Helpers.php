<?php

use App\Models\AuditLog;
use App\Models\GbpConnection;
use App\Models\SenderIdentity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared across Feature/Admin tests (and anything else that needs an
 * admin/customer account or a fake Horizon supervisor) — a global PHP
 * function can only be declared once per process, so these moved here out
 * of the single test file that originally owned them the moment a second
 * file needed the same helper.
 */
function seedAdminAccount(string $label): array
{
    $tenantId = (string) Str::uuid();
    $email = strtolower(str_replace(' ', '', $label)).'-admin-'.uniqid().'@example.com';
    $password = 'correct-horse-battery-staple';

    DB::transaction(function () use ($tenantId, $label, $email, $password) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => "{$label} Co", 'type' => 'admin']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();
    });

    $login = test()->postJson('/api/v1/login', ['email' => $email, 'password' => $password])
        ->assertOk();

    expect($login->json('data.user.is_admin'))->toBeTrue();

    return [$login->json('data.token'), $tenantId];
}

/**
 * Verified by default (see markEmailVerified() below) — the "add email
 * verification" decision doc adds a real, enforced gate
 * (Tenant::sendingBlockedReason()'s 'email_unverified' case), and the
 * overwhelming majority of call sites across this suite exist to test
 * something else entirely and simply need "a real account that can send"
 * as setup, same reasoning makeVerifiedSenderIdentity() below is verified
 * by default rather than making every caller opt in. Tests that actually
 * exercise the unverified-blocking behavior itself use
 * seedUnverifiedCustomerAccount() instead
 * (tests/Feature/Auth/EmailVerificationTest.php).
 */
function seedCustomerAccount(string $label): array
{
    [$token, $tenantId, $userId] = seedUnverifiedCustomerAccount($label);

    markEmailVerified($userId, $tenantId);

    return [$token, $tenantId];
}

/**
 * The real registration flow, deliberately left unverified — for
 * EmailVerificationTest.php's own tests of the gate itself. Returns the
 * user id too (seedCustomerAccount's own callers never needed it, hence
 * the 2-element return there) since a caller here needs it to build a
 * real signed verification link the same way
 * AuthController::verifyEmail()'s own route expects.
 */
function seedUnverifiedCustomerAccount(string $label): array
{
    $response = test()->postJson('/api/v1/register', [
        'name' => "{$label} Owner",
        'business_name' => "{$label} Co",
        'email' => strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    return [$response->json('data.token'), $response->json('data.tenant.id'), $response->json('data.user.id')];
}

/**
 * Direct DB write, not a real click-the-link flow (EmailVerificationTest.php
 * covers that end to end) — same "don't route every test through the full
 * flow when the flow itself isn't what's under test" convention
 * seedTeamMember()/expireTrialDirect() already use elsewhere in this suite.
 */
function markEmailVerified(int $userId, string $tenantId): void
{
    DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        User::query()->find($userId)?->forceFill(['email_verified_at' => now()])->save();
    });
}

/**
 * Directly creates a role='member' user on an existing tenant, bypassing
 * the invite-email flow — the invite/accept flow itself is exercised for
 * real by tests/Feature/Team/TeamInviteTest.php; every other test that
 * just needs "a member with these permissions already on this tenant" (to
 * exercise RequirePermission/EnsureTenantOwner) uses this instead, same
 * "don't route every test through the full flow when the flow itself
 * isn't what's under test" convention as makeVerifiedSenderIdentity above.
 */
function seedTeamMember(string $tenantId, string $label, array $permissions = []): array
{
    $email = strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com';
    $password = 'correct-horse-battery-staple';

    $userId = DB::transaction(function () use ($tenantId, $label, $email, $password, $permissions) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $user = new User([
            'name' => $label,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'member';
        $user->permissions = $permissions;
        $user->save();

        return $user->id;
    });

    $login = test()->postJson('/api/v1/login', ['email' => $email, 'password' => $password])->assertOk();

    return [$login->json('data.token'), $userId];
}

function auditLogsForAction(string $action): Collection
{
    return DB::transaction(function () use ($action) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return AuditLog::withoutGlobalScopes()->where('action', $action)->get();
    });
}

/**
 * personal_access_tokens now carries its own RLS policy
 * (2026_08_06_133207_add_tenant_id_and_rls_to_personal_access_tokens_table.php)
 * — a raw PersonalAccessToken::findToken() call in test body code (not
 * behind any 'tenant'-middleware request) has no tenant context active,
 * same is_admin bypass pattern as auditLogsForAction() above, scoped to
 * this one lookup for test-assertion purposes only.
 */
function findTokenAsAdmin(string $plaintext): ?PersonalAccessToken
{
    return DB::transaction(function () use ($plaintext) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return PersonalAccessToken::findToken($plaintext);
    });
}

/**
 * Find-then-mutate has to happen inside the same bypass-active
 * transaction as findTokenAsAdmin() above — the transaction commits (and
 * app.is_admin reverts) as soon as the closure returns, so a separate
 * ->save() call afterward would run with no tenant context active and
 * silently affect zero rows under the normal tenant_isolation policy.
 */
function expireTokenNow(string $plaintext): void
{
    DB::transaction(function () use ($plaintext) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        PersonalAccessToken::findToken($plaintext)
            ?->forceFill(['expires_at' => now()->subMinute()])
            ->save();
    });
}

/**
 * .claude tests/Feature/Queue/WorkerHeartbeatTest.php: no real Redis-backed
 * Horizon master exists in this suite — MasterSupervisorRepository is faked
 * with a plain in-memory double so "Horizon is/isn't running" is fully
 * controlled per test.
 */
class FakeMasterSupervisorRepository implements MasterSupervisorRepository
{
    public function __construct(private readonly array $masters = []) {}

    public function names()
    {
        return array_keys($this->masters);
    }

    public function all()
    {
        return $this->masters;
    }

    public function find($name)
    {
        return $this->masters[$name] ?? null;
    }

    public function get(array $names)
    {
        return array_intersect_key($this->masters, array_flip($names));
    }

    public function update($master) {}

    public function forget($name) {}

    public function flushExpired() {}
}

function bindMasterSupervisors(array $masters): void
{
    app()->instance(MasterSupervisorRepository::class, new FakeMasterSupervisorRepository($masters));
}

/**
 * Shared by tests/Feature/Queue/SendReviewRequestTest.php and
 * ReleasePendingContactsTest.php — SendReviewRequest needs a verified
 * sender identity to have anywhere to send *from*, same requirement the
 * job itself enforces before attempting a send.
 */
function makeVerifiedSenderIdentity(string $tenantId, string $fromEmail = 'hello@example.com', string $fromName = 'Test Business'): void
{
    DB::transaction(function () use ($tenantId, $fromEmail, $fromName) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $identity = new SenderIdentity(['from_name' => $fromName, 'from_email' => $fromEmail, 'verified' => true]);
        $identity->tenant_id = $tenantId;
        $identity->save();
    });
}

/**
 * A connected GBP connection with a review_link — SendReviewRequest's
 * only source for the one link every contact gets (.claude/CLAUDE.md
 * golden rule #3), so it refuses to send without one.
 */
function makeConnectedGbpConnection(string $tenantId, string $reviewLink = 'https://g.page/r/test-location/review'): void
{
    DB::transaction(function () use ($tenantId, $reviewLink) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $connection = new GbpConnection([
            'oauth_token' => 'fake-oauth-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/test-123',
            'review_link' => $reviewLink,
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantId;
        $connection->save();
    });
}
