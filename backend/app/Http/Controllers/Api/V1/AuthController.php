<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WelcomeEmail;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

        $token = $this->createTokenForUser($user, 'auth');

        // "Register and log in while unverified — do not block login
        // itself" (the "add email verification" decision doc): sent
        // after the token above, never inside the DB::transaction() that
        // created $user — a queued notification firing before that
        // transaction's own commit is confirmed would be a side effect
        // racing ahead of the write it depends on, same reasoning
        // createTokenForUser() itself is already called outside that
        // transaction for.
        $user->sendEmailVerificationNotification();

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

        $token = $this->createTokenForUser($user, 'auth');

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

    /**
     * Same reasoning as resolveIsAdmin() just above: by the time either
     * register() or login() reaches this point, whatever transaction had
     * app.current_tenant_id active has already committed and reverted —
     * neither method keeps a tenant context open for its full duration.
     * $user->createToken() now stamps tenant_id directly onto the new
     * personal_access_tokens row (User::createToken() override), but
     * that row's own tenant_isolation policy still independently checks
     * the session's app.current_tenant_id via its WITH CHECK clause
     * (2026_08_06_133207_add_tenant_id_and_rls_to_personal_access_tokens_table.php)
     * — the two have to agree, or the insert is rejected outright.
     * $user->tenant_id is trustworthy here (this exact User row's own
     * column, never client input), so setting it as the real tenant
     * context for this one insert is legitimate, not a bypass.
     */
    private function createTokenForUser(User $user, string $name): string
    {
        return DB::transaction(function () use ($user, $name) {
            $this->setRlsSessionVar('app.current_tenant_id', $user->tenant_id);

            return $user->createToken($name)->plainTextToken;
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'data' => ['message' => 'Logged out.'],
        ]);
    }

    /**
     * Public route (named `verification.verify`, `signed` middleware) —
     * clicked from an email, so it carries no Sanctum bearer token, same
     * class of exception as /gbp/callback, sender-identities.verify.
     * Deliberately NOT
     * Illuminate\Foundation\Auth\EmailVerificationRequest: that class's
     * authorize() calls $this->user() — it assumes the web session guard
     * already knows who's asking, which fits Breeze/Jetstream's
     * session-based verification link but not this app (Sanctum
     * token-only, no session auth at all, and a token can't ride along on
     * an email link anyway). The `id` route parameter plus the `hash`
     * re-check below are what identify the user instead — the same two
     * checks EmailVerificationRequest::authorize() makes, just without
     * requiring an active session to make them.
     *
     * Users lookup bypasses RLS the same way login()'s email lookup does
     * (users.tenant_isolation_auth_lookup policy, app.bypass_tenant_scope)
     * — there's no tenant context to resolve the row from before it's
     * found. $user->tenant_id is trustworthy once found (this exact row's
     * own column), so it's set as the real tenant context for the actual
     * write, same reasoning resolveIsAdmin()/createTokenForUser() use.
     *
     * "No re-login required" (the decision doc's own test criterion):
     * this never touches Sanctum tokens at all — hasVerifiedEmail() is
     * read fresh from the DB by RequireSendingAccess on every subsequent
     * request, so an already-issued token starts passing the gate
     * immediately, no new token needed.
     *
     * Also where WelcomeEmail fires — verification, not registration, is
     * the "real account" moment it's tied to. Sent from inside the
     * `! hasVerifiedEmail()` guard, never unconditionally, so it can only
     * ever fire on the actual unverified-to-verified transition, not on
     * a repeat visit to an already-used link.
     */
    public function verifyEmail(Request $request, int $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');

        $user = DB::transaction(function () use ($id) {
            $this->setRlsSessionVar('app.bypass_tenant_scope', 'true');

            $user = User::find($id);

            $this->setRlsSessionVar('app.bypass_tenant_scope', 'false');

            return $user;
        });

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away("{$frontendUrl}/login?verified=0");
        }

        if (! $user->hasVerifiedEmail()) {
            $tenant = DB::transaction(function () use ($user) {
                $this->setRlsSessionVar('app.current_tenant_id', $user->tenant_id);
                $user->markEmailAsVerified();

                return Tenant::query()->find($user->tenant_id);
            });

            event(new Verified($user));

            // Verification, not registration, is the "real account"
            // moment this fires on — inside the `! hasVerifiedEmail()`
            // guard above, not unconditionally, so a repeat visit to an
            // already-used link (or any other call into this method
            // after the first) can never send it twice.
            //
            // QA-audit fix (Finding 1, CRITICAL): ad-hoc mail route, never
            // $user->notify(...) — see App\Notifications\
            // VerifyEmailAddress's docblock for the full root cause
            // (a real queue worker has no RLS tenant context, so
            // Laravel's automatic re-fetch of $user by id to serve as
            // $notifiable silently failed and this email was never sent).
            // WelcomeEmail::toMail() never reads $notifiable, so no
            // further change is needed there.
            if ($tenant !== null) {
                Notification::route('mail', $user->email)->notify(new WelcomeEmail(
                    tenantName: $tenant->name,
                    onboardingCompleted: $tenant->onboarding_completed_at !== null,
                ));
            }
        }

        return redirect()->away("{$frontendUrl}/settings?verified=1");
    }

    /**
     * The "Resend verification email" action (Settings) — authenticated,
     * throttled (routes/api.php), safe to click repeatedly: a no-op
     * message once already verified rather than sending a pointless
     * second email.
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['message' => 'Your email is already verified.']]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => ['message' => 'Verification email sent.']]);
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
            // The "add email verification" decision doc: registering and
            // logging in both succeed regardless of this — surfaced here
            // (and on GET /tenant) purely so the frontend can show the
            // "verify your email" banner/resend action without a separate
            // request right after register/login.
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }
}
