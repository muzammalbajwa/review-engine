<?php

namespace App\Support\Tenancy;

/**
 * Request-scoped holder for the resolved tenant id. Bound as a singleton so
 * the tenant-context middleware (writer) and the TenantScope global scope
 * (reader) share one instance per request.
 *
 * Deliberately has no way to resolve a tenant on its own — something else
 * (the middleware, resolving from auth()->user()->tenant_id) must call
 * set(). Never resolves from request input; see .claude/SECURITY.md #2.
 */
class CurrentTenant
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function resolved(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
