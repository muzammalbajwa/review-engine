<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layer B of the two-layer isolation model (.claude/SECURITY.md #2,
 * .claude/DATABASE.md). Runs as the owner/superuser connection (this
 * migration itself is DDL + role management, never runtime app code).
 *
 * Policy branches, per table:
 *  - tenant_isolation:        tenant_id (or id, on `tenants`) matches the
 *                              resolved tenant. The normal case.
 *  - tenant_isolation_admin:  app.is_admin session flag. Consumed by the
 *                              Phase 1 Step 7 admin cross-tenant path.
 *                              Present on every tenant table.
 *  - tenant_isolation_auth_lookup (users only): app.bypass_tenant_scope
 *    session flag, set only inside the login controller's one
 *    credential-lookup query — login has to find a user by email before any
 *    tenant is known. Scoped to `users` alone to keep the blast radius of
 *    this exception as small as possible; no other table needs it.
 *
 * All current_setting() calls use missing_ok=true (the second argument).
 * This is required, not just a style choice: Postgres does not guarantee
 * left-to-right short-circuit evaluation of OR'd boolean expressions, so a
 * strict current_setting() call that throws when unset could abort the
 * whole policy check even when a later OR branch would have matched. Using
 * missing_ok=true everywhere means an unset session variable quietly
 * evaluates to "no match" instead — safe (no cross-tenant leak either way),
 * just silent rather than a thrown error when the tenant-context middleware
 * is ever accidentally skipped.
 */
return new class extends Migration
{
    private const TENANT_ID_TABLES = ['users', 'subscriptions'];

    public function up(): void
    {
        $this->createAppUserRole();
        $this->grantAppUserPrivileges();
        $this->enableRlsOnTenantsTable();
        $this->enableRlsOnTenantIdTables();
    }

    public function down(): void
    {
        foreach (self::TENANT_ID_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_admin ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP POLICY IF EXISTS tenant_isolation_auth_lookup ON users');

        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tenants');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON tenants');
        DB::statement('ALTER TABLE tenants NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenants DISABLE ROW LEVEL SECURITY');

        $owner = $this->ownerRole();
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public REVOKE ALL ON TABLES FROM app_user");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public REVOKE ALL ON SEQUENCES FROM app_user");
        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM app_user');
        DB::statement('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM app_user');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM app_user');
        DB::statement('DROP ROLE IF EXISTS app_user');
    }

    private function ownerRole(): string
    {
        return env('DB_OWNER_USERNAME', 'postgres');
    }

    private function createAppUserRole(): void
    {
        $password = env('DB_APP_USER_PASSWORD');

        if (empty($password)) {
            throw new RuntimeException(
                'DB_APP_USER_PASSWORD is not set. Refusing to create app_user without an explicit password.'
            );
        }

        // Idempotent: safe to re-run across environments. Explicit
        // NOSUPERUSER/NOBYPASSRLS/NOCREATEDB/NOCREATEROLE even though these
        // are the defaults for a new role — states outright, rather than
        // relying on defaults, that this role cannot bypass RLS.
        DB::statement('
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = \'app_user\') THEN
                    CREATE ROLE app_user LOGIN PASSWORD ' . DB::connection()->getPdo()->quote($password) . '
                        NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
                END IF;
            END
            $$;
        ');
    }

    private function grantAppUserPrivileges(): void
    {
        $database = DB::connection()->getDatabaseName();
        $owner = $this->ownerRole();

        DB::statement("GRANT CONNECT ON DATABASE {$database} TO app_user");
        DB::statement('GRANT USAGE ON SCHEMA public TO app_user');

        // Existing tables/sequences now...
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_user');
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_user');

        // ...and anything future migrations create later, automatically —
        // so app_user's grants don't need a new migration every phase.
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_user");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_user");
    }

    private function enableRlsOnTenantsTable(): void
    {
        DB::statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenants FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON tenants
            USING (id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON tenants
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }

    private function enableRlsOnTenantIdTables(): void
    {
        foreach (self::TENANT_ID_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            ");

            DB::statement("
                CREATE POLICY tenant_isolation_admin ON {$table}
                USING (current_setting('app.is_admin', true) = 'true')
            ");
        }

        DB::statement("
            CREATE POLICY tenant_isolation_auth_lookup ON users
            USING (current_setting('app.bypass_tenant_scope', true) = 'true')
        ");
    }
};
