<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\AcceptTeamInviteRequest;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Public — no Sanctum bearer token exists yet (same class of exception as
 * /register, /gbp/callback, /lemon-squeezy/webhook). Both actions look up
 * a TeamInvite by its token ALONE, before any tenant is known — the same
 * bootstrapping problem AuthController::login()'s email lookup solves,
 * solved the same way: a narrow, set-immediately-before/cleared-
 * immediately-after RLS bypass (app.bypass_tenant_scope), never a bypass
 * left active for anything else.
 */
class TeamInviteController extends Controller
{
    /**
     * The invite-landing screen's data — tenant name + the email this
     * invite was sent to, so the frontend can render "You're invited to
     * join {tenant}" before the person has an account or a session.
     */
    public function show(string $token): JsonResponse
    {
        $invite = $this->findPendingInvite($token);

        if ($invite === null) {
            return $this->invalidInviteResponse();
        }

        return response()->json(['data' => [
            'tenant_name' => $this->tenantNameFor($invite),
            'email' => $invite->email,
        ]]);
    }

    public function accept(AcceptTeamInviteRequest $request, string $token): JsonResponse
    {
        $invite = $this->findPendingInvite($token);

        if ($invite === null) {
            return $this->invalidInviteResponse();
        }

        $user = DB::transaction(function () use ($invite, $request) {
            $this->setRlsSessionVar('app.current_tenant_id', $invite->tenant_id);

            $user = new User([
                'name' => $request->validated('name'),
                'email' => $invite->email,
                'password' => Hash::make($request->validated('password')),
            ]);
            $user->tenant_id = $invite->tenant_id;
            $user->role = 'member';
            $user->save();

            $invite->accepted_at = now();
            $invite->save();

            return $user;
        });

        [$authToken, $tenantName] = DB::transaction(function () use ($user) {
            $this->setRlsSessionVar('app.current_tenant_id', $user->tenant_id);

            return [$user->createToken('auth')->plainTextToken, $user->tenant->name];
        });

        return response()->json([
            'data' => [
                'token' => $authToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    // Self-serve invite acceptance always joins a
                    // type=customer tenant — same reasoning as
                    // AuthController::register(), admin tenants are never
                    // reachable through a self-serve flow.
                    'is_admin' => false,
                ],
                'tenant' => ['id' => $user->tenant_id, 'name' => $tenantName],
            ],
        ], 201);
    }

    private function findPendingInvite(string $token): ?TeamInvite
    {
        return DB::transaction(function () use ($token) {
            $this->setRlsSessionVar('app.bypass_tenant_scope', 'true');

            $invite = TeamInvite::where('token', $token)->first();

            $this->setRlsSessionVar('app.bypass_tenant_scope', 'false');

            return $invite !== null && $invite->isPending() ? $invite : null;
        });
    }

    private function tenantNameFor(TeamInvite $invite): string
    {
        return DB::transaction(function () use ($invite) {
            $this->setRlsSessionVar('app.current_tenant_id', $invite->tenant_id);

            return $invite->tenant->name;
        });
    }

    private function invalidInviteResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'invite_invalid',
            'message' => 'This invite link is invalid or has expired.',
            'fields' => null,
        ], 404);
    }

    private function setRlsSessionVar(string $name, string $value): void
    {
        DB::statement('SELECT set_config(?, ?, true)', [$name, $value]);
    }
}
