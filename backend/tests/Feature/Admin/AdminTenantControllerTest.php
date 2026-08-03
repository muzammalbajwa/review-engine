<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * .claude/API.md: "Admin: GET /admin/tenants, GET /admin/tenants/{id}/*
 * (logged, admin only)". .claude/DATABASE.md: "EVERY admin cross-tenant
 * read writes an audit_log row." This had no test coverage at all before
 * this file — the Phase 1 gate's "cross-tenant isolation" tests cover
 * regular tenant tables, not this separate, RLS-bypassing admin path, so
 * none of its behavior (the 403 for non-admins, the audit logging, the
 * actual cross-tenant read) was previously verified.
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

    // Regression coverage for the login is_admin resolution (AuthController
    // ::resolveIsAdmin) — an earlier version crashed here with "Attempt to
    // read property 'type' on null" because it read $user->isAdmin() after
    // tenant context had already reverted.
    expect($login->json('data.user.is_admin'))->toBeTrue();

    return [$login->json('data.token'), $tenantId];
}

function seedCustomerAccount(string $label): array
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

function auditLogsForAction(string $action): Collection
{
    return DB::transaction(function () use ($action) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return AuditLog::withoutGlobalScopes()->where('action', $action)->get();
    });
}

test('listing tenants requires authentication', function () {
    $this->getJson('/api/v1/admin/tenants')->assertUnauthorized();
});

test('a non-admin tenant cannot list tenants', function () {
    [$token] = seedCustomerAccount('Non Admin List');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants')
        ->assertForbidden();
});

test('a non-admin tenant cannot view another tenant either', function () {
    [$token] = seedCustomerAccount('Non Admin Show');
    [, $otherTenantId] = seedCustomerAccount('Non Admin Show Target');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/admin/tenants/{$otherTenantId}")
        ->assertForbidden();
});

test('an admin can list every tenant across the system, and the read is audit logged', function () {
    [$adminToken, $adminTenantId] = seedAdminAccount('List All');
    [, $customerATenantId] = seedCustomerAccount('List All Customer A');
    [, $customerBTenantId] = seedCustomerAccount('List All Customer B');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($adminTenantId, $customerATenantId, $customerBTenantId);

    $logs = auditLogsForAction('admin.tenants.list');
    expect($logs->where('target', 'all')->isNotEmpty())->toBeTrue();
});

test('an admin can view a single tenant by id, and the read is audit logged', function () {
    [$adminToken] = seedAdminAccount('View One');
    [, $customerTenantId] = seedCustomerAccount('View One Target');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson("/api/v1/admin/tenants/{$customerTenantId}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($customerTenantId);
    expect($response->json('data.name'))->toBe('View One Target Co');

    $logs = auditLogsForAction('admin.tenants.view');
    expect($logs->where('target', $customerTenantId)->isNotEmpty())->toBeTrue();
});

test('viewing a non-existent tenant id returns a clean 404, not a raw SQL error', function () {
    [$adminToken] = seedAdminAccount('View Missing');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants/'.Str::uuid());

    $response->assertNotFound();
    expect($response->json('error'))->toBe('not_found');
});

test('a malformed tenant id in the URL does not reach the database', function () {
    [$adminToken] = seedAdminAccount('View Malformed');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants/not-a-uuid');

    $response->assertNotFound();
});
