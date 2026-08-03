<?php

use App\Notifications\WorkerHeartbeatDown;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * .claude/QUEUE.md: "Heartbeat alert if the worker dies... This alert ships
 * in Phase 2, not later." .claude/TESTING.md gate 6: "Worker-death alarm
 * fires when worker killed." No real Redis-backed Horizon master exists in
 * this suite — MasterSupervisorRepository is faked with a plain in-memory
 * double so "Horizon is/isn't running" is fully controlled per test.
 */
class FakeMasterSupervisorRepository implements MasterSupervisorRepository
{
    public function __construct(private readonly array $masters = [])
    {
    }

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

    public function update($master)
    {
    }

    public function forget($name)
    {
    }

    public function flushExpired()
    {
    }
}

function bindMasterSupervisors(array $masters): void
{
    app()->instance(MasterSupervisorRepository::class, new FakeMasterSupervisorRepository($masters));
}

beforeEach(function () {
    Cache::forget('worker_heartbeat_alert_sent');
});

test('the check passes silently when a master supervisor is active', function () {
    bindMasterSupervisors(['this-host' => (object) ['status' => 'running']]);
    Notification::fake();
    Log::shouldReceive('critical')->never();

    $this->artisan('queue:check-heartbeat')->assertExitCode(0);

    Notification::assertNothingSent();
});

test('the check logs critical and alerts when no master supervisor is registered at all', function () {
    bindMasterSupervisors([]);
    config(['services.ops.alert_email' => 'ops@example.com']);
    Notification::fake();
    Log::shouldReceive('critical')->once()->with(\Mockery::pattern('/Horizon is not running/'));

    $this->artisan('queue:check-heartbeat')->assertExitCode(1);

    Notification::assertSentOnDemand(
        WorkerHeartbeatDown::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.com'
    );
});

test('the check also treats a paused master supervisor as down', function () {
    bindMasterSupervisors(['this-host' => (object) ['status' => 'paused']]);
    config(['services.ops.alert_email' => 'ops@example.com']);
    Notification::fake();
    Log::shouldReceive('critical')->once()->with(\Mockery::pattern('/paused/'));

    $this->artisan('queue:check-heartbeat')->assertExitCode(1);

    Notification::assertSentOnDemandTimes(WorkerHeartbeatDown::class, 1);
});

test('repeated failing checks within the throttle window log every time but only alert once', function () {
    bindMasterSupervisors([]);
    config(['services.ops.alert_email' => 'ops@example.com']);
    Notification::fake();
    Log::shouldReceive('critical')->twice();

    $this->artisan('queue:check-heartbeat')->assertExitCode(1);
    $this->artisan('queue:check-heartbeat')->assertExitCode(1);

    Notification::assertSentOnDemandTimes(WorkerHeartbeatDown::class, 1);
});

test('a fresh outage after a recovery alerts again, not suppressed by the earlier throttle window', function () {
    bindMasterSupervisors([]);
    config(['services.ops.alert_email' => 'ops@example.com']);
    Notification::fake();
    Log::shouldReceive('critical')->twice();

    $this->artisan('queue:check-heartbeat')->assertExitCode(1);

    bindMasterSupervisors(['this-host' => (object) ['status' => 'running']]);
    $this->artisan('queue:check-heartbeat')->assertExitCode(0);

    bindMasterSupervisors([]);
    $this->artisan('queue:check-heartbeat')->assertExitCode(1);

    Notification::assertSentOnDemandTimes(WorkerHeartbeatDown::class, 2);
});

test('with no ops alert email configured, the check still logs critical without erroring', function () {
    bindMasterSupervisors([]);
    config(['services.ops.alert_email' => null]);
    Notification::fake();
    Log::shouldReceive('critical')->once();

    $this->artisan('queue:check-heartbeat')->assertExitCode(1);

    Notification::assertNothingSent();
});
