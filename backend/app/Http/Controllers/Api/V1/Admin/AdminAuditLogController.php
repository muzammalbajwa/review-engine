<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogsRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * .claude/DATABASE.md: "EVERY admin cross-tenant read writes an audit_log
 * row" — that applies to reading audit_logs itself as much as any other
 * tenant table (it has a tenant_id column and the same RLS policy shape as
 * every other core table), so this list goes through AdminAuditLogger too,
 * same as AdminTenantController.
 */
class AdminAuditLogController extends Controller
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);

        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        $logs = $this->auditLogger->record(
            actor: $request->user(),
            tenantId: null,
            action: 'admin.auditlog.list',
            target: 'all',
            meta: array_filter([
                'actor_id' => $filters['actor_id'] ?? null,
                'tenant_id' => $filters['tenant_id'] ?? null,
                'action' => $filters['action'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ]),
            read: fn () => AuditLog::query()
                ->with([
                    // TenantScope (Layer A) filters User queries to the
                    // acting admin's own tenant regardless of the RLS
                    // admin bypass above — an audit row for another
                    // tenant's actor would otherwise eager-load a null
                    // actor even though the row itself is visible.
                    'actor' => fn ($query) => $query->withoutGlobalScopes(),
                    'tenant',
                ])
                ->when(
                    $filters['actor_id'] ?? null,
                    fn ($query, $actorId) => $query->where('actor_id', $actorId)
                )
                ->when(
                    $filters['tenant_id'] ?? null,
                    fn ($query, $tenantId) => $query->where('tenant_id', $tenantId)
                )
                ->when(
                    $filters['action'] ?? null,
                    fn ($query, $action) => $query->where('action', 'like', '%'.$action.'%')
                )
                ->when(
                    $filters['from'] ?? null,
                    fn ($query, $from) => $query->where('created_at', '>=', $from)
                )
                ->when(
                    $filters['to'] ?? null,
                    fn ($query, $to) => $query->where('created_at', '<=', $to)
                )
                ->orderByDesc('created_at')
                ->paginate($perPage)
                ->through(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'actor' => $log->actor === null ? null : [
                        'id' => $log->actor->id,
                        'name' => $log->actor->name,
                        'email' => $log->actor->email,
                    ],
                    'action' => $log->action,
                    'target' => $log->target,
                    'tenant' => $log->tenant === null ? null : [
                        'id' => $log->tenant->id,
                        'name' => $log->tenant->name,
                    ],
                    'meta' => $log->meta,
                    'created_at' => $log->created_at,
                ]),
        );

        // AdminAuditLogController's own list, mirroring
        // AdminTenantController's list/show wrapping so the frontend's
        // apiFetch<Paginated<T>> pattern works identically here.
        return response()->json(['data' => $logs]);
    }
}
