<?php

use Illuminate\Support\Carbon;

/**
 * .claude/CLAUDE.md webhook API spec: "Keys must be rotatable from
 * Settings without downtime (old key works for a grace period after a
 * new one is issued, not an instant cutover)." Dashboard-session
 * authenticated (the existing 'tenant' Sanctum group), not part of the
 * public webhook surface these keys unlock.
 */
test('viewing api keys requires authentication', function () {
    $this->getJson('/api/v1/api-keys')->assertUnauthorized();
});

test('issuing an api key requires authentication', function () {
    $this->postJson('/api/v1/api-keys')->assertUnauthorized();
});

test('a fresh tenant has no api keys', function () {
    [$token] = seedCustomerAccount('No Keys Yet');

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/api-keys');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

test('issuing a key returns the plaintext token once, scoped to contacts:create only', function () {
    [$token] = seedCustomerAccount('Issue Key');

    $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');

    $response->assertCreated();
    expect($response->json('data.token'))->toBeString();
    expect($response->json('data.key.status'))->toBe('active');
    expect($response->json('data.key.expires_at'))->toBeNull();

    $plaintext = $response->json('data.token');
    $accessToken = findTokenAsAdmin($plaintext);

    expect($accessToken)->not->toBeNull();
    expect($accessToken->abilities)->toBe(['contacts:create']);
});

test('the plaintext token is never returned again from the status endpoint', function () {
    [$token] = seedCustomerAccount('No Replay');

    $issued = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');
    $plaintext = $issued->json('data.token');

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/api-keys');

    expect(json_encode($status->json()))->not->toContain($plaintext);
});

test('rotating gives the old key a grace period instead of deleting it immediately', function () {
    [$token] = seedCustomerAccount('Rotate Grace');

    $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');
    $firstPlaintext = $first->json('data.token');
    $firstId = $first->json('data.key.id');

    $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');
    $secondPlaintext = $second->json('data.token');

    expect($firstPlaintext)->not->toBe($secondPlaintext);

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/api-keys');
    $keys = collect($status->json('data'))->keyBy('id');

    expect($keys[$firstId]['status'])->toBe('expiring');
    expect($keys[$firstId]['expires_at'])->not->toBeNull();
    expect(Carbon::parse($keys[$firstId]['expires_at'])->diffInHours(now()))->toBeLessThanOrEqual(24);

    $newKeyId = $second->json('data.key.id');
    expect($keys[$newKeyId]['status'])->toBe('active');
});

test('rotating returns the graced key\'s real expiry in the same response, not just a generic grace period', function () {
    [$token] = seedCustomerAccount('Graced Keys In Response');

    $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');
    $firstId = $first->json('data.key.id');
    expect($first->json('data.graced_keys'))->toBe([]);

    $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');

    $graced = collect($second->json('data.graced_keys'))->keyBy('id');
    expect($graced->has($firstId))->toBeTrue();
    expect($graced[$firstId]['status'])->toBe('expiring');
    expect($graced[$firstId]['expires_at'])->not->toBeNull();
    expect(Carbon::parse($graced[$firstId]['expires_at'])->diffInHours(now()))->toBeLessThanOrEqual(24);

    // Matches exactly what GET /api-keys would report for the same key —
    // the response isn't a different or approximate view of it.
    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/api-keys');
    $fromStatus = collect($status->json('data'))->keyBy('id')[$firstId];
    expect($graced[$firstId]['expires_at'])->toBe($fromStatus['expires_at']);
});

test('an old key still authenticates during its grace period after rotation', function () {
    [$token] = seedCustomerAccount('Grace Still Works');

    $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');
    $oldPlaintext = $first->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys')->assertCreated();

    // The old key, now mid-grace-period, still authenticates against any
    // 'tenant'-protected route — not just the webhook endpoint.
    $response = $this->withHeader('Authorization', "Bearer {$oldPlaintext}")->getJson('/api/v1/tenant');

    $response->assertOk();
});

test('an old key stops working once its grace period has actually elapsed', function () {
    [$token] = seedCustomerAccount('Grace Expired');

    $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys');
    $oldPlaintext = $first->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/api-keys')->assertCreated();

    // Force the grace period to actually be over, rather than waiting 24h.
    expireTokenNow($oldPlaintext);

    $response = $this->withHeader('Authorization', "Bearer {$oldPlaintext}")->getJson('/api/v1/tenant');

    $response->assertUnauthorized();
});
