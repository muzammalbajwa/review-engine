<?php

use App\Models\GbpConnection;
use Illuminate\Support\Facades\DB;

/**
 * GET /gbp/status — read-only surface for the frontend's connection
 * settings screen (connected / not_connected / revoked). Added alongside
 * the frontend GBP screen; existing connect/callback behavior is
 * untouched.
 */
function registerAndGetTokenForGbpStatus(string $label): array
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

function seedGbpConnectionForStatus(string $tenantId, array $overrides = []): GbpConnection
{
    return DB::transaction(function () use ($tenantId, $overrides) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $connection = new GbpConnection(array_merge([
            'oauth_token' => 'super-secret-access-token',
            'refresh_token' => 'super-secret-refresh-token',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/1',
            'review_link' => 'https://g.page/r/test/review',
            'status' => 'connected',
        ], $overrides));
        $connection->tenant_id = $tenantId;
        $connection->save();

        return $connection;
    });
}

test('status requires authentication', function () {
    $this->getJson('/api/v1/gbp/status')->assertUnauthorized();
});

test('status is not_connected when the tenant has never connected', function () {
    [$token] = registerAndGetTokenForGbpStatus('Never Connected');

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/status');

    $response->assertOk();
    expect($response->json('data.status'))->toBe('not_connected');
    expect($response->json('data.location_id'))->toBeNull();
});

test('status reflects a connected connection, with its review link', function () {
    [$token, $tenantId] = registerAndGetTokenForGbpStatus('Connected Status');
    seedGbpConnectionForStatus($tenantId, ['review_link' => 'https://g.page/r/example/review']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/status');

    $response->assertOk();
    expect($response->json('data.status'))->toBe('connected');
    expect($response->json('data.review_link'))->toBe('https://g.page/r/example/review');
});

test('status reflects a revoked connection', function () {
    [$token, $tenantId] = registerAndGetTokenForGbpStatus('Revoked Status');
    seedGbpConnectionForStatus($tenantId, ['status' => 'revoked']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/status');

    $response->assertOk();
    expect($response->json('data.status'))->toBe('revoked');
});

test('status never leaks the oauth or refresh token', function () {
    [$token, $tenantId] = registerAndGetTokenForGbpStatus('No Leak');
    seedGbpConnectionForStatus($tenantId);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/status');

    $response->assertOk();
    $body = $response->json('data');
    expect($body)->not->toHaveKey('oauth_token');
    expect($body)->not->toHaveKey('refresh_token');
    expect(json_encode($body))->not->toContain('super-secret');
});

test('a tenant only ever sees its own connection status', function () {
    [$tokenA, $tenantAId] = registerAndGetTokenForGbpStatus('Status Isolation A');
    [$tokenB, $tenantBId] = registerAndGetTokenForGbpStatus('Status Isolation B');

    seedGbpConnectionForStatus($tenantAId, ['status' => 'revoked']);
    seedGbpConnectionForStatus($tenantBId, ['status' => 'connected']);

    $responseA = $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/gbp/status');
    expect($responseA->json('data.status'))->toBe('revoked');

    $responseB = $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/gbp/status');
    expect($responseB->json('data.status'))->toBe('connected');
});
