<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * .claude/API.md: "Admin: GET /admin/tenants, GET /admin/tenants/{id}/*
 * (logged, admin only)". Deliberately does not use route-model-binding for
 * the {tenant} parameter — binding runs through the normal Eloquent query
 * before authorization even happens, and would 404 on any tenant other than
 * the caller's own. Authorization checks only the acting user (TenantPolicy
 * ::viewAny), then AdminAuditLogger performs the actual cross-tenant read
 * under a narrowly-scoped, audited RLS bypass.
 */
class AdminTenantController extends Controller
{
    public function __construct(private readonly AdminAuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);

        $tenants = $this->auditLogger->record(
            actor: $request->user(),
            tenantId: null,
            action: 'admin.tenants.list',
            target: 'all',
            read: fn () => Tenant::query()->orderBy('created_at')->get(['id', 'name', 'type', 'created_at']),
        );

        return response()->json(['data' => $tenants]);
    }

    public function show(Request $request, string $tenant): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);

        $tenantRow = $this->auditLogger->record(
            actor: $request->user(),
            tenantId: $tenant,
            action: 'admin.tenants.view',
            target: $tenant,
            read: fn () => Tenant::query()->find($tenant, ['id', 'name', 'type', 'created_at']),
        );

        if ($tenantRow === null) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'The requested resource was not found.',
                'fields' => null,
            ], 404);
        }

        return response()->json(['data' => $tenantRow]);
    }
}
