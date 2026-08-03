<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$tenant, $user] = DB::transaction(function () use ($data) {
            $tenantId = (string) Str::uuid();

            // Inserting the new tenant row requires the session's
            // tenant context to already equal its own id — there's no
            // prior context to inherit it from (Phase 1 Step 3).
            $this->setRlsSessionVar('app.current_tenant_id', $tenantId);

            // Same reasoning as login's lookup: checking whether this
            // email is already taken has to look across all tenants,
            // which the normal tenant-scoped policy can't do. Kept out
            // of RegisterRequest's declarative rules for this reason —
            // see RegisterRequest's docblock.
            //
            // CurrentTenant (the Eloquent-layer scope, Layer A of
            // SECURITY.md #2) is deliberately NOT set at this point. If it
            // were, TenantScope would add `where tenant_id = <this
            // brand-new tenant's id>` to the query below — which has zero
            // rows by definition, since the tenant was just generated — so
            // the check would always report "not taken" no matter what.
            // The RLS bypass below only lifts the *database* policy; it
            // does nothing about the separate Eloquent-level scope.
            $this->setRlsSessionVar('app.bypass_tenant_scope', 'true');

            $emailTaken = User::where('email', $data['email'])->exists();

            $this->setRlsSessionVar('app.bypass_tenant_scope', 'false');

            if ($emailTaken) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already registered.'],
                ]);
            }

            $tenant = new Tenant(['name' => $data['business_name'], 'type' => 'customer']);
            $tenant->id = $tenantId;
            $tenant->save();

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            // Explicit, not auto-filled via BelongsToTenant/CurrentTenant —
            // CurrentTenant is intentionally never set in this method (see
            // above), so there's nothing for the auto-fill to read.
            $user->tenant_id = $tenantId;
            $user->role = 'owner';
            $user->save();

            return [$tenant, $user];
        });

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                // Self-serve registration always creates a type=customer
                // tenant (hardcoded above) — is_admin is trivially false
                // here, no query needed. Admin tenants are only ever seeded
                // directly (PROJECT_STATUS.md: "no self-serve way to create
                // one — by design"), never through this endpoint.
                'user' => $this->userPayload($user, isAdmin: false),
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ],
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $user = DB::transaction(function () use ($data) {
                // Narrow, explicit, login-only exception (Phase 1 Step 3):
                // finding a user by email has to look across all tenants,
                // since the tenant isn't known until *after* the user is
                // found. Scoped to this one query alone.
                $this->setRlsSessionVar('app.bypass_tenant_scope', 'true');

                return User::where('email', $data['email'])->first();
            });
        } finally {
            app(CurrentTenant::class)->clear();
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // AuthenticationException (401), not ValidationException (422)
            // — per .claude/API.md's own taxonomy, wrong credentials is an
            // auth failure, not a data-shape problem. Deliberately the same
            // message either way — do not reveal whether the email exists.
            throw new AuthenticationException('These credentials do not match our records.');
        }

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user, $this->resolveIsAdmin($user)),
            ],
        ]);
    }

    /**
     * $user->isAdmin() can't be called directly here: it lazy-loads the
     * `tenant` relation, and by this point in login() the bypass query's
     * transaction has already committed — app.current_tenant_id reverted to
     * whatever it was before (never this user's own tenant), so the read
     * would be silently blocked by the `tenants` table's own RLS policy and
     * return null (caught by this method's own test, not assumed safe: an
     * earlier version crashed here with "Attempt to read property 'type' on
     * null"). $user->tenant_id is trustworthy — it came from the row we just
     * authenticated, not client input — so it's legitimate (not a bypass) to
     * set it as the real tenant context for this one read.
     */
    private function resolveIsAdmin(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $this->setRlsSessionVar('app.current_tenant_id', $user->tenant_id);

            return Tenant::query()->where('id', $user->tenant_id)->value('type') === 'admin';
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'data' => ['message' => 'Logged out.'],
        ]);
    }

    private function setRlsSessionVar(string $name, string $value): void
    {
        // set_config(), not "SET LOCAL ... = ?" — Postgres's SET/SET LOCAL
        // is a utility statement and rejects bind parameters outright
        // (confirmed against real Postgres, Phase 1 Step 3). true = local
        // to this transaction.
        DB::statement('SELECT set_config(?, ?, true)', [$name, $value]);
    }

    private function userPayload(User $user, bool $isAdmin): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            // .claude/CLAUDE.md: admin cross-tenant access is determined by
            // tenant type, not a per-user flag (see User::isAdmin(),
            // TenantPolicy). Surfaced here purely as a UI hint so the
            // frontend knows whether to show the admin nav link — the
            // actual authorization on every admin request is still
            // TenantPolicy::viewAny, checked server-side regardless of what
            // this says.
            'is_admin' => $isAdmin,
        ];
    }
}
