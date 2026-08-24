<?php

/**
 * .claude/API.md: "Tenant: GET/PATCH /tenant (self)" — documented since
 * the API contract was first written, never implemented until /settings'
 * business profile section needed it.
 */
test('viewing your own tenant requires authentication', function () {
    $this->getJson('/api/v1/tenant')->assertUnauthorized();
});

test('updating your own tenant requires authentication', function () {
    $this->patchJson('/api/v1/tenant', ['name' => 'New Name'])->assertUnauthorized();
});

test('a tenant sees its own name, type, and id', function () {
    [$token, $tenantId] = seedCustomerAccount('My Own Tenant');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/tenant');

    $response->assertOk();
    expect($response->json('data.id'))->toBe($tenantId);
    expect($response->json('data.quick_add_token'))->not->toBeNull();
    expect(strlen($response->json('data.quick_add_token')))->toBe(48);
    expect($response->json('data.name'))->toBe('My Own Tenant Co');
    expect($response->json('data.type'))->toBe('customer');
});

test('a tenant can update its own business name', function () {
    [$token] = seedCustomerAccount('Rename Me');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['name' => 'The Renamed Business']);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('The Renamed Business');

    $refetch = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');
    expect($refetch->json('data.name'))->toBe('The Renamed Business');
});

test('an empty name is rejected', function () {
    [$token] = seedCustomerAccount('Empty Name Reject');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['name' => ''])
        ->assertStatus(422);
});

test('tenant A updating its own name never affects tenant B', function () {
    [$tokenA] = seedCustomerAccount('Isolation A');
    [$tokenB, $tenantBId] = seedCustomerAccount('Isolation B');

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->patchJson('/api/v1/tenant', ['name' => 'Tenant A Renamed']);

    $responseB = $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/tenant');
    expect($responseB->json('data.id'))->toBe($tenantBId);
    expect($responseB->json('data.name'))->toBe('Isolation B Co');
});
