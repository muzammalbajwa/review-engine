<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\QuickAddContactRequest;
use App\Models\Tenant;
use App\Services\Contacts\ContactEnrollmentService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public counterpart to ContactController::quickAdd — reached via the
 * per-tenant shareable link (reviewengine.com/quick/{token}), no login
 * required. ResolveQuickAddTenant (route middleware) has already resolved
 * and activated the tenant context by the time either action here runs;
 * both just work against the normal, non-bypass RLS policy from there —
 * exactly like an authenticated request would.
 */
class QuickAddController extends Controller
{
    public function __construct(
        private readonly ContactEnrollmentService $contactEnrollmentService,
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * So the form can show "Add a customer for {business_name}" —
     * confirms to whoever's standing at the job site that they're on the
     * right link, without exposing anything beyond the business name.
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = Tenant::query()->find($this->currentTenant->id());

        return response()->json(['data' => ['business_name' => $tenant->name]]);
    }

    public function store(QuickAddContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->contactEnrollmentService->create(
            $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            'quick_add',
        );

        return response()->json(['data' => ['message' => 'Added.']], 201);
    }
}
