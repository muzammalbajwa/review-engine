<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\CurrentTenant;

/**
 * Apply to every tenant-owned model. Registers Layer A of the two-layer
 * isolation model (.claude/SECURITY.md #2) and auto-fills tenant_id on
 * create from the resolved tenant.
 *
 * Auto-fill only fires when the model doesn't already have a tenant_id set
 * and a tenant is actually resolved — an explicit assignment (e.g. during
 * registration, where a brand new tenant is created and assigned before any
 * "current tenant" exists) always wins. If neither happens, tenant_id stays
 * unset and the column's NOT NULL constraint fails loudly rather than
 * silently defaulting to the wrong tenant.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if (empty($model->tenant_id)) {
                $tenantId = app(CurrentTenant::class)->id();

                if ($tenantId !== null) {
                    $model->tenant_id = $tenantId;
                }
            }
        });
    }
}
