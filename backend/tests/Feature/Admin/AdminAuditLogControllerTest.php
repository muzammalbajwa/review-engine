<?php

/**
 * .claude/DATABASE.md: "EVERY admin cross-tenant read writes an audit_log
 * row" — applies recursively to reading the audit log itself. New UI for a
 * table that's existed since Phase 1 with no dedicated screen or test
 * coverage of its own read path (only ever exercised indirectly as a side
 * effect of AdminTenantControllerTest).
 */
test('listing the audit log requires authentication', function () {
    $this->getJson('/api/v1/admin/audit-log')->assertUnauthorized();
});

test('a non-admin tenant cannot list the audit log', function () {
    [$token] = seedCustomerAccount('Audit Log Non Admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/audit-log')
        ->assertForbidden();
});

test('an admin sees audit rows across every tenant, with actor and target tenant resolved', function () {
    [$adminToken] = seedAdminAccount('Audit Log List');
    [, $customerTenantId] = seedCustomerAccount('Audit Log List Target');

    // Generates a real admin.tenants.view audit row against a tenant the
    // admin account has no other relationship to.
    $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson("/api/v1/admin/tenants/{$customerTenantId}")
        ->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/audit-log');

    $response->assertOk();

    $rows = collect($response->json('data.data'));
    $row = $rows->firstWhere('action', 'admin.tenants.view');

    expect($row)->not->toBeNull();
    expect($row['target'])->toBe($customerTenantId);
    expect($row['tenant']['id'])->toBe($customerTenantId);
    // The actor belongs to a different tenant than the one being viewed —
    // this only resolves if the User eager load bypasses TenantScope.
    expect($row['actor'])->not->toBeNull();
    expect($row['actor']['email'])->toContain('auditloglist-admin-');

    // Viewing the audit log is itself an audited admin action.
    $logs = auditLogsForAction('admin.auditlog.list');
    expect($logs->isNotEmpty())->toBeTrue();
});

test('the audit log can be filtered by action', function () {
    [$adminToken] = seedAdminAccount('Audit Log Filter Action');
    [, $customerTenantId] = seedCustomerAccount('Audit Log Filter Action Target');

    $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants')
        ->assertOk();
    $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson("/api/v1/admin/tenants/{$customerTenantId}")
        ->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/audit-log?action=admin.tenants.view');

    $response->assertOk();
    $actions = collect($response->json('data.data'))->pluck('action')->unique();

    expect($actions->every(fn ($action) => $action === 'admin.tenants.view'))->toBeTrue();
});

test('the audit log can be filtered by target tenant', function () {
    [$adminToken] = seedAdminAccount('Audit Log Filter Tenant');
    [, $tenantA] = seedCustomerAccount('Audit Log Filter Tenant A');
    [, $tenantB] = seedCustomerAccount('Audit Log Filter Tenant B');

    $this->withHeader('Authorization', "Bearer {$adminToken}")->getJson("/api/v1/admin/tenants/{$tenantA}")->assertOk();
    $this->withHeader('Authorization', "Bearer {$adminToken}")->getJson("/api/v1/admin/tenants/{$tenantB}")->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson("/api/v1/admin/audit-log?tenant_id={$tenantA}");

    $response->assertOk();
    $tenantIds = collect($response->json('data.data'))->pluck('tenant.id')->unique();

    expect($tenantIds->all())->toBe([$tenantA]);
});

test('an invalid tenant_id filter is rejected with a validation error, not passed to the query', function () {
    [$adminToken] = seedAdminAccount('Audit Log Invalid Filter');

    $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/audit-log?tenant_id=not-a-uuid')
        ->assertStatus(422);
});

test('per_page is capped at 100', function () {
    [$adminToken] = seedAdminAccount('Audit Log Per Page Cap');

    $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/audit-log?per_page=500')
        ->assertStatus(422);
});
