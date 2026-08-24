<?php

namespace App\Console\Commands;

use App\Notifications\WorkerHeartbeatDown;
use App\Services\Queue\WorkerHeartbeatChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * .claude/QUEUE.md: "Heartbeat alert if the worker dies — a silent worker
 * means every tenant's reviews stop. This alert ships in Phase 2, not
 * later." .claude/TESTING.md gate 6: "Worker-death alarm fires when worker
 * killed."
 *
 * Run by the OS-level cron -> `schedule:run` (routes/console.php), NOT by
 * a queue worker itself — that's the one property that makes this actually
 * work: if Horizon and every worker process die, cron keeps running
 * independently and is still the one thing left able to notice and alert.
 * Detection (an empty/paused master supervisor list in Horizon's
 * Redis-backed repository, the same mechanism `php artisan horizon:status`
 * uses) lives in WorkerHeartbeatChecker, shared with the /admin/system
 * screen's live status — this command owns only the alerting/throttling
 * side effects of a failing check.
 */
class CheckWorkerHeartbeat extends Command
{
    protected $signature = 'queue:check-heartbeat';

    protected $description = "Alert if Horizon's master supervisor has gone silent or paused.";

    private const ALERT_THROTTLE_KEY = 'worker_heartbeat_alert_sent';

    private const ALERT_THROTTLE_MINUTES = 15;

    /**
     * Method-injected, not constructor-injected: Artisan resolves a
     * command's constructor dependencies once, when it's first registered
     * with the console application, and reuses that same instance for
     * every subsequent `artisan queue:check-heartbeat` call in the process
     * — a constructor-injected checker (which itself constructor-injects
     * MasterSupervisorRepository) would go stale forever after the first
     * run. handle()'s parameters are resolved fresh from the container on
     * every single invocation instead.
     */
    public function handle(WorkerHeartbeatChecker $checker): int
    {
        ['healthy' => $healthy, 'reason' => $reason] = $checker->check();

        if ($healthy) {
            // A fresh future outage should always alert immediately, not
            // get silently swallowed by a throttle window left over from a
            // previous, since-resolved incident.
            Cache::forget(self::ALERT_THROTTLE_KEY);
            $this->info('Worker heartbeat OK.');

            return self::SUCCESS;
        }

        // Logged on every failing check regardless of the alert throttle
        // below — cheap, durable, and never silently skipped.
        Log::critical("Worker heartbeat check failed: {$reason}");

        $this->sendAlertOnce($reason);

        $this->error("Worker heartbeat check failed: {$reason}");

        return self::FAILURE;
    }

    private function sendAlertOnce(string $reason): void
    {
        if (Cache::has(self::ALERT_THROTTLE_KEY)) {
            return;
        }

        Cache::put(self::ALERT_THROTTLE_KEY, true, now()->addMinutes(self::ALERT_THROTTLE_MINUTES));

        $email = config('services.ops.alert_email');

        if (empty($email)) {
            // Nothing configured to page — the critical log line above is
            // the only trail until OPS_ALERT_EMAIL is set.
            return;
        }

        Notification::route('mail', $email)->notify(new WorkerHeartbeatDown($reason));
    }
}
