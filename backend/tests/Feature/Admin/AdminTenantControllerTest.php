<?php

use Illuminate\Support\Str;

/**
 * .claude/API.md: "Admin: GET /admin/tenants, GET /admin/tenants/{id}/*
 * (logged, admin only)". .claude/DATABASE.md: "EVERY admin cross-tenant
 * read writes an audit_log row." This had no test coverage at all before
 * this file — the Phase 1 gate's "cross-tenant isolation" tests cover
 * regular tenant tables, not this separate, RLS-bypassing admin path, so
 * none of its behavior (the 403 for non-admins, the audit logging, the
 * actual cross-tenant read) was previously verified.
 *
 * seedAdminAccount/seedCustomerAccount/auditLogsForAction live in
 * tests/Helpers.php — shared with the other Feature/Admin test files.
 */
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
