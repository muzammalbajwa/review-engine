<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Layer A of the two-layer isolation model (.claude/SECURITY.md #2). Adds
 * `where tenant_id = <resolved tenant>` to every query automatically.
 *
 * No-ops when no tenant is resolved yet — e.g. the login endpoint's
 * User::where('email', ...) lookup happens before authentication, so there
 * is no tenant to scope by. That's expected and safe: an unscoped lookup by
 * a globally-unique email is not a tenant-data leak. Layer B (Postgres RLS)
 * is the backstop if this scope is ever forgotten or bypassed on a request
 * that *should* have had a tenant resolved.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), '=', $tenantId);
        }
    }
}
