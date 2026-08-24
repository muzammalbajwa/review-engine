<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\WebhookCreateContactRequest;
use App\Models\Contact;
use App\Services\Contacts\ContactEnrollmentService;
use Illuminate\Http\JsonResponse;

/**
 * The public, versioned webhook API (.claude/CLAUDE.md: "a public,
 * versioned Webhook API as a first-class product surface... treat it
 * with the same rigor as the Lemon Squeezy webhook handling already in
 * this codebase"). POST /api/v1/contacts — versioned by both the /v1/ route
 * prefix AND this controller's own Api\V1 namespace, so a future v2 with
 * different behavior lives in Api\V2\WebhookContactController under a
 * separate Route::prefix('v2') group, never touching this class.
 *
 * Auth (a valid, unexpired, contacts:create-scoped API key) and rate
 * limiting are both route middleware (routes/api.php: 'tenant',
 * 'abilities:contacts:create', 'throttle:webhook-api') — every failure
 * mode that isn't this controller's own business logic (idempotency)
 * already flows through the app's existing, consistent error envelope
 * with zero new exception-handling code:
 *   - missing/invalid/expired key -> AuthenticationException -> 401
 *   - key valid but wrong ability -> MissingAbilityException -> 403
 *   - malformed payload           -> ValidationException      -> 422
 *   - over the plan's rate limit  -> ThrottleRequestsException -> 429
 */
class WebhookContactController extends Controller
{
    /**
     * .claude/CLAUDE.md: "dedupe on (tenant_id, external_id) within a
     * reasonable window" — not permanent (see the external_id migration's
     * own docblock for why this isn't a unique constraint). 24h covers
     * realistic webhook-retry storms (most senders give up in minutes to
     * a few hours) without permanently reserving an external_id forever.
     */
    private const IDEMPOTENCY_WINDOW_HOURS = 24;

    public function __construct(private readonly ContactEnrollmentService $contactEnrollmentService) {}

    public function store(WebhookCreateContactRequest $request): JsonResponse
    {
        $data = $request->validated();
        $externalId = $data['external_id'] ?? null;

        if ($externalId !== null) {
            // TenantScope (BelongsToTenant) already scopes this to the
            // calling key's own tenant — no explicit tenant_id needed,
            // same convention as every other query in this controller
            // family (ContactController::index, etc.).
            $duplicate = Contact::query()
                ->where('external_id', $externalId)
                ->where('created_at', '>=', now()->subHours(self::IDEMPOTENCY_WINDOW_HOURS))
                ->first();

            if ($duplicate !== null) {
                // .claude/CLAUDE.md: "Return the original response on a
                // duplicate call, not an error." 200, not 201 — nothing
                // new was created.
                return response()->json(['data' => $duplicate], 200);
            }
        }

        $contact = $this->contactEnrollmentService->create(
            $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            'webhook',
            $externalId,
        );

        return response()->json(['data' => $contact], 201);
    }
}
