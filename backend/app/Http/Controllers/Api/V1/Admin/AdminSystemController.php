<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Queue\WorkerHeartbeatChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The ops signal `queue:check-heartbeat` already alerts on by email
 * (.claude/QUEUE.md), surfaced as a UI for the first time. Not
 * tenant-scoped data (no tenant_id on failed_jobs, no RLS on it either) —
 * .claude/DATABASE.md's audit-logging rule is specifically about admin
 * cross-*tenant* reads, which this isn't, so it doesn't go through
 * AdminAuditLogger.
 */
class AdminSystemController extends Controller
{
    private const FAILED_JOBS_WINDOW_HOURS = 24;

    public function __construct(private readonly WorkerHeartbeatChecker $heartbeatChecker) {}

    public function status(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);

        $heartbeat = $this->heartbeatChecker->check();

        $since = now()->subHours(self::FAILED_JOBS_WINDOW_HOURS);

        // Query Builder, not DB::raw (.claude/SECURITY.md #1) — failed_jobs
        // isn't an Eloquent model anywhere in the app since nothing but
        // this read ever needs it.
        $recentFailures = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->get(['queue', 'payload', 'failed_at']);

        $byJob = $recentFailures
            ->map(fn ($row) => data_get(json_decode($row->payload, true), 'displayName', 'unknown'))
            ->countBy()
            ->map(fn ($count, $job) => ['job' => $job, 'count' => $count])
            ->values()
            ->sortByDesc('count')
            ->values();

        return response()->json(['data' => [
            'heartbeat' => $heartbeat,
            'failed_jobs' => [
                'window_hours' => self::FAILED_JOBS_WINDOW_HOURS,
                'total' => $recentFailures->count(),
                'by_job' => $byJob,
            ],
        ]]);
    }
}
