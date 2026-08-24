<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\InviteTeamMemberRequest;
use App\Http\Requests\Team\UpdateMemberPermissionsRequest;
use App\Models\TeamInvite;
use App\Models\User;
use App\Notifications\TeamInviteReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner-only (EnsureTenantOwner, routes/api.php) — the two-tier
 * owner/member structure within a customer tenant. Separate from the
 * cross-tenant admin system (tenants.type/TenantPolicy), never touched
 * here.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $members = User::query()
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->memberPayload($user));

        $invites = TeamInvite::query()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TeamInvite $invite) => [
                'id' => $invite->id,
                'email' => $invite->email,
                'invited_at' => $invite->created_at,
                'expires_at' => $invite->expires_at,
            ]);

        return response()->json(['data' => ['members' => $members, 'pending_invites' => $invites]]);
    }

    /**
     * A freshly-invited member starts with zero permissions (the safe
     * default — see users.permissions migration's own docblock). The
     * owner grants access afterward from the team list, once the invite
     * is accepted.
     */
    public function invite(InviteTeamMemberRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $alreadyMember = User::query()->where('email', $email)->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => ['This person is already on your team.'],
            ]);
        }

        $pendingInvite = TeamInvite::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($pendingInvite) {
            throw ValidationException::withMessages([
                'email' => ['An invite is already pending for this email.'],
            ]);
        }

        $invite = new TeamInvite([
            'email' => $email,
            'token' => Str::random(48),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);
        $invite->save();

        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');
        $acceptUrl = "{$frontendUrl}/team/invite/{$invite->token}";

        Notification::route('mail', $email)->notify(
            new TeamInviteReceived($request->user()->tenant->name, $request->user()->name, $acceptUrl)
        );

        return response()->json(['data' => [
            'id' => $invite->id,
            'email' => $invite->email,
            'invited_at' => $invite->created_at,
            'expires_at' => $invite->expires_at,
        ]], 201);
    }

    public function revokeInvite(Request $request, TeamInvite $invite): JsonResponse
    {
        if (! $invite->isPending()) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'The requested resource was not found.',
                'fields' => null,
            ], 404);
        }

        $invite->revoked_at = now();
        $invite->save();

        return response()->json(['data' => ['message' => 'Invite revoked.']]);
    }

    /**
     * The owner's own row can never be edited through this endpoint —
     * there's no permission key that would do anything to an owner
     * (User::hasPermission() short-circuits true regardless), and this
     * blocks the confusing no-op before it happens rather than silently
     * accepting and discarding it.
     */
    public function updatePermissions(UpdateMemberPermissionsRequest $request, User $member): JsonResponse
    {
        if ($member->isOwner()) {
            return response()->json([
                'error' => 'owner_only',
                'message' => 'The account owner\'s access can\'t be changed.',
                'fields' => null,
            ], 422);
        }

        $updates = $request->validated('permissions');
        $permissions = array_merge($member->permissions ?? [], $updates);

        $member->permissions = $permissions;
        $member->save();

        return response()->json(['data' => $this->memberPayload($member->refresh())]);
    }

    private function memberPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'permissions' => $user->isOwner()
                ? array_fill_keys(User::PERMISSION_RESOURCES, true)
                : array_merge(array_fill_keys(User::PERMISSION_RESOURCES, false), $user->permissions ?? []),
        ];
    }
}
