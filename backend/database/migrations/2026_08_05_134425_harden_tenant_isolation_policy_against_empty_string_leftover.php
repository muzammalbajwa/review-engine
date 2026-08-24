<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real, confirmed bug, found while building the send pipeline (empirical
 * repro, not a hunch):
 *
 *   DB::transaction(fn () => DB::statement("SELECT set_config('app.current_tenant_id', '<real-uuid>', true)"));
 *   DB::transaction(fn () => DB::selectOne("SELECT current_setting('app.current_tenant_id', true) as v"));
 *   // => '' (empty string), NOT null.
 *
 * set_config(..., true) is transaction-LOCAL, so it should revert once
 * that transaction commits — and it does revert, but to '' rather than to
 * "truly unset," on a connection where the GUC has ever been assigned a
 * value before. HTTP requests never surfaced this because PHP-FPM/
 * artisan serve typically don't reuse one Postgres connection across many
 * different requests' worth of SET LOCAL history the way a long-lived
 * queue worker process does — Horizon/`queue:work` runs many jobs in a
 * row on the exact same connection, and this is the first feature in the
 * app whose jobs (SendReviewRequest, ReleasePendingContacts) set tenant
 * context repeatedly in that kind of loop.
 *
 * The failure mode: every tenant_isolation policy does
 * `tenant_id = current_setting('app.current_tenant_id', true)::uuid`.
 * ''::uuid raises "invalid input syntax for type uuid" — a hard error,
 * not a false/no-match — and Postgres does not guarantee left-to-right
 * short-circuit evaluation of an OR'd USING clause (already documented in
 * 2026_07_17_145636_enable_row_level_security.php's own docblock, for
 * exactly this reason, re: missing_ok=true). So a *second consecutive*
 * job on the same worker connection could throw on a query it was
 * genuinely entitled to make (even the is_admin bypass), purely because
 * some earlier job on that connection had ever set a real tenant id.
 *
 * Fix: NULLIF(..., '') before the cast, everywhere the cast happens. An
 * empty-string leftover now maps to NULL, so the comparison is NULL (no
 * match) instead of a thrown error — the exact "quietly evaluates to no
 * match" behavior missing_ok=true already documents as the intended
 * design, just not actually achieved by the bare cast alone. This changes
 * nothing about who is allowed to see what: a real tenant id still has to
 * equal the row's tenant_id, and is_admin still bypasses via its own
 * separate policy, unaffected by this.
 */
return new class extends Migration
{
    private const TENANT_ID_KEYED_TABLES = [
        'users', 'subscriptions', 'audit_logs', 'campaigns', 'contacts',
        'gbp_connections', 'reviews', 'replies', 'templates',
        'sender_identities', 'timing_rules', 'messages',
    ];

    public function up(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tenants');
        DB::statement("
            CREATE POLICY tenant_isolation ON tenants
            USING (id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
        ");

        foreach (self::TENANT_ID_KEYED_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tenants');
        DB::statement("
            CREATE POLICY tenant_isolation ON tenants
            USING (id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        foreach (self::TENANT_ID_KEYED_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            ");
        }
    }
};
