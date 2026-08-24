<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * .claude/QUEUE.md's worker heartbeat alert existed only as an email
 * before this — this is its first UI. FakeMasterSupervisorRepository /
 * bindMasterSupervisors live in tests/Helpers.php, shared with
 * Feature/Queue/WorkerHeartbeatTest.php, so this endpoint is exercised
 * against the exact same fake Horizon double, never a real one.
 */
function insertFailedJob(string $displayName, string $queue = 'default', ?DateTimeInterface $failedAt = null): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => $queue,
        'payload' => json_encode(['displayName' => $displayName]),
        'exception' => 'Exception: something broke',
        'failed_at' => $failedAt ?? now(),
    ]);
}

// No beforeEach cleanup needed: DatabaseTransactions (tests/Pest.php) rolls
// back every write, including these raw failed_jobs inserts, at the end of
// each test — and app_user (the test connection, matching production) has
// no TRUNCATE privilege anyway, only DML.

test('the system status endpoint requires authentication', function () {
    $this->getJson('/api/v1/admin/system')->assertUnauthorized();
});

test('a non-admin tenant cannot view system status', function () {
    [$token] = seedCustomerAccount('System Status Non Admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/system')
        ->assertForbidden();
});

test('an admin sees a healthy heartbeat and zero recent failures when nothing is wrong', function () {
    bindMasterSupervisors(['this-host' => (object) ['status' => 'running']]);
    [$adminToken] = seedAdminAccount('System Status Healthy');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/system');

    $response->assertOk();
    expect($response->json('data.heartbeat.healthy'))->toBeTrue();
    expect($response->json('data.heartbeat.reason'))->toBeNull();
    expect($response->json('data.failed_jobs.total'))->toBe(0);
    expect($response->json('data.failed_jobs.by_job'))->toBe([]);
});

test('an admin sees the worker as down with the same reason the heartbeat alert would use', function () {
    bindMasterSupervisors([]);
    [$adminToken] = seedAdminAccount('System Status Down');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/system');

    $response->assertOk();
    expect($response->json('data.heartbeat.healthy'))->toBeFalse();
    expect($response->json('data.heartbeat.reason'))->toContain('Horizon is not running');
});

test('recent failed jobs are counted and grouped by job class', function () {
    bindMasterSupervisors(['this-host' => (object) ['status' => 'running']]);
    [$adminToken] = seedAdminAccount('System Status Failures');

    insertFailedJob('App\\Jobs\\SendReviewRequest');
    insertFailedJob('App\\Jobs\\SendReviewRequest');
    insertFailedJob('App\\Jobs\\SyncReviewsForConnection');
    // Outside the 24h window — must not be counted.
    insertFailedJob('App\\Jobs\\SendReviewRequest', failedAt: now()->subDays(3));

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/system');

    $response->assertOk();
    expect($response->json('data.failed_jobs.total'))->toBe(3);

    $byJob = collect($response->json('data.failed_jobs.by_job'))->keyBy('job');
    expect($byJob['App\\Jobs\\SendReviewRequest']['count'])->toBe(2);
    expect($byJob['App\\Jobs\\SyncReviewsForConnection']['count'])->toBe(1);
});
