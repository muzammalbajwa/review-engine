<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Notifications\VerifyEmailAddress;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * .claude/SECURITY.md #3 / the "add email verification" decision doc:
 * MustVerifyEmailContract + the MustVerifyEmail trait are Laravel's real,
 * built-in verification primitives (hasVerifiedEmail()/markEmailAsVerified()/
 * getEmailForVerification(), the signed verification.verify route, the
 * Verified event) — nothing about the mechanism itself is reinvented here.
 * sendEmailVerificationNotification() is overridden below purely to queue
 * the notification (Laravel's own VerifyEmail sends synchronously by
 * default), matching every other outbound notification in this app.
 */
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, MustVerifyEmail, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * tenant_id and role are deliberately excluded: tenant_id must only ever
     * come from CurrentTenant (via auth, never request input — see
     * .claude/SECURITY.md #2) or explicit assignment during registration,
     * and role must never be settable from arbitrary client input (privilege
     * escalation risk).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'has_completed_welcome_tour' => 'boolean',
            'tours_seen' => 'array',
            'permissions' => 'array',
        ];
    }

    /**
     * The four independently grantable resources (.claude decision doc) —
     * the single source of truth UpdateMemberPermissionsRequest,
     * TeamController, and TenantController's own payload all reference,
     * so the whitelist can never drift between "what a client is allowed
     * to toggle" and "what the API reports back."
     */
    public const PERMISSION_RESOURCES = ['contacts', 'templates', 'reviews', 'analytics'];

    /**
     * 'owner' is set once, at registration (AuthController::register) —
     * never client-settable (see $fillable's own exclusion above).
     * 'member' is the only other value, set by TeamInviteController's
     * accept() action. Nothing in this codebase writes any other role.
     */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * The per-member permission gate (contacts/templates/reviews/analytics
     * — see RequirePermission middleware). An owner is never gated by
     * $permissions at all, regardless of what it contains — this method
     * short-circuits true before ever reading the column. Billing and team
     * management deliberately never go through this method: they're
     * owner-only by construction (EnsureTenantOwner middleware checks
     * isOwner() directly), not a permission key a bug or a stray toggle
     * could ever grant to a member.
     *
     * A key absent from $permissions defaults to false (not granted) —
     * a freshly-invited member starts with zero access.
     */
    public function hasPermission(string $resource): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        return (bool) ($this->permissions[$resource] ?? false);
    }

    /**
     * Marks a contextual, per-screen tour as seen. Not exposed as a raw
     * mass-assignable attribute — same reasoning as tenant_id/role above:
     * this only ever gets set through TourController's own validated
     * write path, never from an arbitrary client PATCH.
     */
    public function markScreenTourSeen(string $key): void
    {
        $seen = $this->tours_seen ?? [];
        $seen[$key] = true;
        $this->tours_seen = $seen;
        $this->save();
    }

    /**
     * Safe to read under normal (non-bypass) RLS: by the time this is
     * called, SetTenantContext has already set app.current_tenant_id to
     * this exact user's own tenant, and the normal tenant_isolation policy
     * on `tenants` permits a tenant reading its own row.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->tenant->type === 'admin';
    }

    /**
     * Overrides HasApiTokens::createToken() to stamp tenant_id on the new
     * token row before insert. Sanctum's own trait has no concept of
     * tenant_id — left alone, the insert would leave it NULL, which
     * personal_access_tokens' tenant_isolation policy's WITH CHECK
     * (tenant_id = ...) rejects outright (2026_08_06_133207_add_tenant_id_
     * and_rls_to_personal_access_tokens_table.php). $this->tenant_id is
     * trustworthy here — this exact User row's own column, never client
     * input — so stamping it directly is legitimate, the same
     * already-known-real-tenant reasoning AuthController::resolveIsAdmin()
     * uses, not a bypass.
     *
     * forceCreate(), not create(): tenant_id isn't in Sanctum's own
     * PersonalAccessToken::$fillable (a vendor model this app doesn't
     * control), so plain mass assignment would throw. forceCreate() is
     * the standard Eloquent escape hatch for writing a trusted value that
     * isn't mass-assignable, without needing a custom token model class.
     *
     * The caller must also have a real app.current_tenant_id already
     * active in the session matching $this->tenant_id — the RLS policy's
     * WITH CHECK still evaluates independently of what's in the row being
     * inserted. AuthController's login()/register() set this explicitly
     * right before calling createToken(); routes behind the 'tenant'
     * middleware (e.g. ApiKeyController::store()) already have it active
     * for the whole request via SetTenantContext.
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)
    {
        $plainTextToken = $this->generateTokenString();

        $token = $this->tokens()->forceCreate([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'tenant_id' => $this->tenant_id,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    /**
     * Overrides MustVerifyEmail::sendEmailVerificationNotification()
     * (which hardcodes `notify(new \Illuminate\Auth\Notifications\VerifyEmail)`)
     * purely to send the queued subclass instead — see
     * App\Notifications\VerifyEmailAddress's own docblock.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailAddress);
    }
}
