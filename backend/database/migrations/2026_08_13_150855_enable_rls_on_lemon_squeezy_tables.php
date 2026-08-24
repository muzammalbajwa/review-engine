<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same policy shape as 2026_07_17_145636_enable_row_level_security.php's
 * enableRlsOnTenantIdTables() — tenant_isolation (tenant_id matches the
 * resolved tenant) + tenant_isolation_admin (app.is_admin) on both of
 * lemonsqueezy/laravel's own tables, now that
 * add_tenant_id_to_lemon_squeezy_{customers,subscriptions}_table have given
 * them a tenant_id column to key the policy on.
 *
 * Why this matters more here than it did for Cashier's subscriptions table:
 * LemonSqueezyWebhookController is the ONE place that sets tenant context
 * before delegating to the package's own webhook processing — without
 * FORCE ROW LEVEL SECURITY here, a bug in that resolution (or the
 * package's own auto-registered, now-disabled /lemon-squeezy/webhook route
 * ever accidentally re-enabled) would write with whatever ambient session
 * state existed instead of failing closed.
 *
 * Uses the NULLIF(...) cast pattern from day one — see
 * 2026_08_05_134425_harden_tenant_isolation_policy_against_empty_string_leftover.php
 * and 2026_08_06_133207_add_tenant_id_and_rls_to_personal_access_tokens_table.php
 * for why a bare ::uuid cast on current_setting() is unsafe on a reused
 * connection (a real, previously-fixed bug in this codebase, not a
 * hypothetical).
 */
return new class extends Migration
{
    private const TABLES = ['lemon_squeezy_customers', 'lemon_squeezy_subscriptions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            ");

            DB::statement("
                CREATE POLICY tenant_isolation_admin ON {$table}
                USING (current_setting('app.is_admin', true) = 'true')
            ");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_admin ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
