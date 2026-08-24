<?php

namespace App\Services\Queue;

use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * The detection logic behind `queue:check-heartbeat` (App\Console\Commands
 * \CheckWorkerHeartbeat), extracted so the same "is Horizon actually
 * running" answer can back both the scheduled alert and the admin
 * /admin/system screen (.claude/QUEUE.md's heartbeat alert existed as an
 * email-only signal before this — this is the same check, not a second
 * one, so the two can never disagree).
 */
class WorkerHeartbeatChecker
{
    public function __construct(private readonly MasterSupervisorRepository $repository) {}

    /**
     * @return array{healthy: bool, reason: ?string}
     */
    public function check(): array
    {
        $masters = collect($this->repository->all());

        $reason = match (true) {
            $masters->isEmpty() => 'no active Horizon master supervisor found — Horizon is not running.',
            $masters->contains(fn ($master) => $master->status === 'paused') => 'the Horizon master supervisor is paused.',
            default => null,
        };

        return ['healthy' => $reason === null, 'reason' => $reason];
    }
}
