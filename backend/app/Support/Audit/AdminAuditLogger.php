<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place a cross-tenant admin read is allowed to happen
 * (.claude/DATABASE.md: "Admin cross-tenant reads... EVERY admin
 * cross-tenant read writes an audit_log row"). Sets app.is_admin narrowly
 * around exactly the read being audited and the audit row itself — same
 * set-immediately-before/clear-immediately-after pattern as every other RLS
 * bypass in this codebase (AuthController's login/register lookups,
 * SetTenantContext's token resolution) — then clears it before returning
 * control to the caller.
 */
class AdminAuditLogger
{
    public function record(User $actor, ?string $tenantId, string $action, string $target, callable $read, array $meta = []): mixed
    {
        return DB::transaction(function () use ($actor, $tenantId, $action, $target, $read, $meta) {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            $result = $read();

            AuditLog::create([
                // A null $read() result means the requested target didn't
                // exist (e.g. AdminTenantController::show() on an id with
                // no matching tenant) — $tenantId in that case is still the
                // caller-supplied, possibly-nonexistent id, and audit_logs.
                // tenant_id is a real FK to `tenants`, so writing it as-is
                // throws a foreign-key violation instead of letting the
                // controller return a clean 404. `target` (a plain string
                // column, not FK-constrained) already preserves exactly
                // which id was requested either way.
                'tenant_id' => $result === null ? null : $tenantId,
                'actor_id' => $actor->id,
                'action' => $action,
                'target' => $target,
                'meta' => $meta,
            ]);

            DB::statement("SELECT set_config('app.is_admin', 'false', true)");

            return $result;
        });
    }
}
