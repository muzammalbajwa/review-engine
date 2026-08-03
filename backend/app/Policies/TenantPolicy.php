<?php

namespace App\Policies;

use App\Models\User;

/**
 * "Full cross-tenant god-access" (.claude/CLAUDE.md) belongs to users whose
 * own tenant is type=admin — not a per-user role flag, which would let a
 * compromised or misconfigured customer account escalate by having its role
 * column changed. Deliberately no `view(User $user, Tenant $tenant)` method:
 * that would need the target Tenant loaded first via route-model-binding,
 * which runs through the normal Eloquent scope + RLS and would 404 on any
 * tenant other than the actor's own before authorization even runs. The
 * admin controller checks viewAny() only, then performs its own explicit,
 * audited, RLS-bypassed lookup for the specific target.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
