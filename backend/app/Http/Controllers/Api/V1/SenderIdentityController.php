<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SenderIdentities\StoreSenderIdentityRequest;
use App\Models\SenderIdentity;
use App\Notifications\VerifySenderIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * .claude/CLAUDE.md Phase 2 Step 2: a simple sender-identity verification
 * flow so the send job (Step 3) never sends from an address the tenant
 * hasn't proven they control.
 */
class SenderIdentityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $identities = SenderIdentity::query()->orderByDesc('id')->get();

        return response()->json(['data' => $identities]);
    }

    public function store(StoreSenderIdentityRequest $request): JsonResponse
    {
        $identity = SenderIdentity::create([
            'from_name' => $request->validated('from_name'),
            'from_email' => $request->validated('from_email'),
            'verified' => false,
        ]);

        $verifyUrl = URL::temporarySignedRoute(
            'sender-identities.verify',
            now()->addDays(2),
            ['tenant' => $identity->tenant_id, 'sender' => $identity->id],
        );

        Notification::route('mail', $identity->from_email)->notify(new VerifySenderIdentity($verifyUrl));

        return response()->json(['data' => $identity], 201);
    }

    /**
     * Public route (named `sender-identities.verify`, `signed` middleware)
     * — clicked from an email, so it carries no Sanctum bearer token, the
     * same class of exception as /gbp/callback. The
     * signed URL itself encodes the tenant id (not just the sender row
     * id): reading a tenant-scoped, RLS-protected row here has the same
     * bootstrapping problem SetTenantContext/GbpOAuthState solve — there's
     * no tenant context to resolve it from before the read. Baking the
     * tenant id into the signature (rather than looking it up) means any
     * attempt to tamper with it invalidates the signature outright,
     * verified by ValidateSignature before this method ever runs.
     */
    public function verify(Request $request, string $tenant, int $sender): JsonResponse
    {
        $verified = DB::transaction(function () use ($tenant, $sender) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenant]);

            $identity = SenderIdentity::query()->find($sender);

            if ($identity === null) {
                return null;
            }

            $identity->verified = true;
            $identity->save();

            return $identity;
        });

        if ($verified === null) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'The requested resource was not found.',
                'fields' => null,
            ], 404);
        }

        return response()->json(['data' => ['message' => 'Sender identity verified.', 'sender_identity' => $verified]]);
    }
}
