<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, BelongsToTenant, Billable;

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
        ];
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
}
