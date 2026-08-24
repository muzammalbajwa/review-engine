<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * app()->instance('env', ...) really does change what app()->environment()
 * resolves (confirmed live via tinker before writing this test, not
 * assumed) — used here to exercise both the refusal path (this Pest suite
 * itself runs under APP_ENV=testing, which is already outside the allowed
 * list, so that path needs no override at all) and the allowed path
 * (overridden to 'local', since the suite's real environment never is).
 */
function tenantIdForDemoEmail(string $email): ?string
{
    return DB::transaction(function () use ($email) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return User::withoutGlobalScopes()->where('email', $email)->value('tenant_id');
    });
}

test('CRITICAL: the seeder refuses to run under the testing environment (already outside local/staging) and creates nothing', function () {
    expect(fn () => (new DemoAccountsSeeder)->run())->toThrow(RuntimeException::class);

    expect(tenantIdForDemoEmail('admin@reviewengine.test'))->toBeNull();
    expect(tenantIdForDemoEmail('owner@riverside-roofing.test'))->toBeNull();
});

test('CRITICAL: the seeder refuses to run under production even when explicitly forced to that environment', function () {
    app()->instance('env', 'production');

    try {
        expect(fn () => (new DemoAccountsSeeder)->run())->toThrow(RuntimeException::class);
        expect(tenantIdForDemoEmail('admin@reviewengine.test'))->toBeNull();
    } finally {
        app()->instance('env', 'testing');
    }
});

test('CRITICAL: under an allowed environment, the seeder creates all three accounts with the right tenant/role/permissions', function () {
    app()->instance('env', 'local');

    try {
        (new DemoAccountsSeeder)->run();
    } finally {
        app()->instance('env', 'testing');
    }

    $adminTenantId = tenantIdForDemoEmail('admin@reviewengine.test');
    $ownerTenantId = tenantIdForDemoEmail('owner@riverside-roofing.test');
    $memberTenantId = tenantIdForDemoEmail('member@riverside-roofing.test');

    expect($adminTenantId)->not->toBeNull();
    expect($ownerTenantId)->not->toBeNull();
    // Owner and member share exactly one tenant — the whole point of the
    // permission system is two roles on the *same* tenant, not two
    // separate accounts.
    expect($memberTenantId)->toBe($ownerTenantId);
    expect($adminTenantId)->not->toBe($ownerTenantId);

    $result = DB::transaction(function () use ($adminTenantId, $ownerTenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return [
            'admin_tenant' => Tenant::withoutGlobalScopes()->find($adminTenantId),
            'admin_user' => User::withoutGlobalScopes()->where('email', 'admin@reviewengine.test')->first(),
            'owner_user' => User::withoutGlobalScopes()->where('email', 'owner@riverside-roofing.test')->first(),
            'member_user' => User::withoutGlobalScopes()->where('email', 'member@riverside-roofing.test')->first(),
            'owner_tenant' => Tenant::withoutGlobalScopes()->find($ownerTenantId),
            'contacts_count' => Contact::withoutGlobalScopes()->where('tenant_id', $ownerTenantId)->count(),
            'templates_count' => Template::withoutGlobalScopes()->where('tenant_id', $ownerTenantId)->count(),
            'templates_all_compliant' => Template::withoutGlobalScopes()->where('tenant_id', $ownerTenantId)->where('compliance_status', '!=', 'pass')->doesntExist(),
            'campaigns_count' => Campaign::withoutGlobalScopes()->where('tenant_id', $ownerTenantId)->count(),
        ];
    });

    expect($result['admin_tenant']->type)->toBe('admin');
    expect($result['admin_user']->role)->toBe('owner');
    expect($result['owner_user']->role)->toBe('owner');
    expect($result['member_user']->role)->toBe('member');

    // The exact "visibly testable" permission split the prompt asked for.
    // ksort both sides: jsonb doesn't guarantee key order is preserved
    // across the round-trip, so a plain ->toBe() on the raw array is
    // comparing incidental storage order, not the actual values.
    $memberPermissions = $result['member_user']->permissions;
    ksort($memberPermissions);
    $expectedPermissions = ['analytics' => true, 'contacts' => false, 'reviews' => true, 'templates' => false];
    ksort($expectedPermissions);
    expect($memberPermissions)->toBe($expectedPermissions);

    expect($result['owner_tenant']->status)->toBe('trialing');
    expect($result['owner_tenant']->onboarding_completed_at)->not->toBeNull();
    expect($result['contacts_count'])->toBe(2);
    expect($result['templates_count'])->toBe(3);
    expect($result['templates_all_compliant'])->toBeTrue();
    expect($result['campaigns_count'])->toBe(1);

    // All three passwords actually authenticate — not just present in the
    // DB, but real, usable credentials (the exact deliverable the prompt
    // asked to see).
    $this->postJson('/api/v1/login', ['email' => 'admin@reviewengine.test', 'password' => 'DemoAdmin123!'])->assertOk();
    $this->postJson('/api/v1/login', ['email' => 'owner@riverside-roofing.test', 'password' => 'DemoOwner123!'])->assertOk();
    $this->postJson('/api/v1/login', ['email' => 'member@riverside-roofing.test', 'password' => 'DemoMember123!'])->assertOk();
});

test('CRITICAL: re-running the seeder resets to a clean state instead of duplicating accounts or erroring on a unique-email conflict', function () {
    app()->instance('env', 'local');

    try {
        (new DemoAccountsSeeder)->run();
        (new DemoAccountsSeeder)->run();
    } finally {
        app()->instance('env', 'testing');
    }

    $ownerTenantId = tenantIdForDemoEmail('owner@riverside-roofing.test');

    $counts = DB::transaction(function () use ($ownerTenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return [
            'admins' => User::withoutGlobalScopes()->where('email', 'admin@reviewengine.test')->count(),
            'owners' => User::withoutGlobalScopes()->where('email', 'owner@riverside-roofing.test')->count(),
            'members' => User::withoutGlobalScopes()->where('email', 'member@riverside-roofing.test')->count(),
            'contacts' => Contact::withoutGlobalScopes()->where('tenant_id', $ownerTenantId)->count(),
            'templates' => Template::withoutGlobalScopes()->where('tenant_id', $ownerTenantId)->count(),
        ];
    });

    expect($counts['admins'])->toBe(1);
    expect($counts['owners'])->toBe(1);
    expect($counts['members'])->toBe(1);
    expect($counts['contacts'])->toBe(2);
    expect($counts['templates'])->toBe(3);
});
