<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same policy shape as enable_rls_on_lemon_squeezy_tables.php — tenant_isolation
 * (tenant_id matches the resolved tenant) + tenant_isolation_admin
 * (app.is_admin) on all four of laravel/cashier-paddle's own tables, now
 * that add_tenant_id_to_cashier_paddle_tables.php has given them a
 * tenant_id column to key the policy on.
 *
 * No webhook/checkout controller exists yet to populate these tables at
 * all (deliberately not built this step) — FORCE ROW LEVEL SECURITY here
 * from the moment the tables exist means that whenever that controller
 * does land, a bug in its tenant-context resolution (or Paddle's own
 * default /paddle/webhook route ever ending up registered instead of a
 * tenant-aware wrapper — see AppServiceProvider's Cashier::ignoreRoutes())
 * fails closed instead of writing with whatever ambient session state
 * happened to be active.
 *
 * Uses the NULLIF(...) cast pattern from day one — see
 * harden_tenant_isolation_policy_against_empty_string_leftover.php and
 * add_tenant_id_and_rls_to_personal_access_tokens_table.php for why a
 * bare ::uuid cast on current_setting() is unsafe on a reused connection
 * (a real, previously-fixed bug in this codebase, not a hypothetical).
 */
return new class extends Migration
{
    private const TABLES = ['customers', 'subscriptions', 'subscription_items', 'transactions'];

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
