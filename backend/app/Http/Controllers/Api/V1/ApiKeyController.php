<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * .claude/CLAUDE.md webhook API spec: "Keys must be rotatable from
 * Settings without downtime (old key works for a grace period after a
 * new one is issued, not an instant cutover that breaks an in-flight
 * integration)." Dashboard-session authenticated (the existing 'tenant'
 * Sanctum group) — managing your own tenant's webhook credential is a
 * normal authenticated dashboard action, not part of the public webhook
 * surface itself.
 *
 * The key IS a Sanctum personal access token ("Reuse the Sanctum token
 * pattern already built"), scoped to the contacts:create ability only.
 * Resolving "this tenant's active key(s)" always looks across every user
 * of the tenant, not just the caller — correct even if a second user is
 * ever added later, not only for today's single-owner-per-tenant reality.
 */
class ApiKeyController extends Controller
{
    private const TOKEN_NAME = 'webhook-api-key';

    private const GRACE_PERIOD_HOURS = 24;

    private const ABILITIES = ['contacts:create'];

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->tenantKeys()->map(fn (PersonalAccessToken $token) => $this->payload($token))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $now = now();
        $graceDeadline = $now->copy()->addHours(self::GRACE_PERIOD_HOURS);

        // Start (or shorten) the grace-period countdown on every
        // currently-valid key before issuing the new one — never leave a
        // previous key working forever, and never cut it off instantly
        // either. A key already closer to expiring than the grace
        // deadline (e.g. a second rotation shortly after the first) keeps
        // its own, shorter deadline rather than being pushed back out.
        //
        // Returned below as `graced_keys` so Settings can show "works
        // until [date]" immediately, from the real deadline just written —
        // not a generic "24 hours" the UI would otherwise have to guess at
        // (this response is the only place that deadline exists until the
        // next GET /api-keys).
        $gracedKeys = $this->tenantKeys()
            ->each(function (PersonalAccessToken $token) use ($graceDeadline) {
                if ($token->expires_at === null || $token->expires_at->gt($graceDeadline)) {
                    $token->forceFill(['expires_at' => $graceDeadline])->save();
                }
            })
            ->map(fn (PersonalAccessToken $token) => $this->payload($token))
            ->values();

        $newToken = $request->user()->createToken(self::TOKEN_NAME, self::ABILITIES);

        return response()->json([
            'data' => [
                // Shown once — Sanctum hashes tokens at rest, same as
                // every other token this app issues (login/register).
                'token' => $newToken->plainTextToken,
                'key' => $this->payload($newToken->accessToken),
                'graced_keys' => $gracedKeys,
            ],
        ], 201);
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    private function tenantKeys(): Collection
    {
        $userIds = User::query()->pluck('id');

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->where('name', self::TOKEN_NAME)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->get();
    }

    private function payload(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->id,
            'created_at' => $token->created_at,
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'status' => $token->expires_at === null ? 'active' : 'expiring',
        ];
    }
}
