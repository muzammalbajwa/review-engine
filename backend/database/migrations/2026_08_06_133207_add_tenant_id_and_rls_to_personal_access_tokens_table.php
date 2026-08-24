<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personal_access_tokens (Sanctum's own table) was the one tenant-adjacent
 * table with neither a tenant_id column nor an RLS policy — protected only
 * by app-layer filtering in ApiKeyController::tenantKeys(), which stayed
 * safe today only because it filters through the RLS-protected `users`
 * table first. Proven exploitable live (2026-08-06 audit): a raw
 * DB::table('personal_access_tokens')->get() issued while Tenant A's
 * session context was active returned all 75 rows on the connection,
 * across every tenant, including Tenant B's. Brings this table in line
 * with golden rule #2 (.claude/CLAUDE.md) like every other tenant table.
 *
 * Every existing row's tokenable_type is App\Models\User with no orphans
 * (confirmed against the live database before writing this migration), so
 * the backfill below is a plain join, and the column can go NOT NULL
 * immediately after.
 *
 * Uses the NULLIF(...) cast pattern from day one — see
 * 2026_08_05_134425_harden_tenant_isolation_policy_against_empty_string_leftover.php
 * for why a bare ::uuid cast on current_setting() is unsafe on a reused
 * connection (Horizon workers). No need to replay that migration's
 * history here; this table never had the unsafe version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('tokenable_id')->constrained('tenants')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE personal_access_tokens
            SET tenant_id = users.tenant_id
            FROM users
            WHERE personal_access_tokens.tokenable_id = users.id
              AND personal_access_tokens.tokenable_type = ?
        ', [User::class]);

        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tenant_id SET NOT NULL');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->index('tenant_id');
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON personal_access_tokens');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON personal_access_tokens');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_auth_lookup ON personal_access_tokens');
        DB::statement('ALTER TABLE personal_access_tokens NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE personal_access_tokens DISABLE ROW LEVEL SECURITY');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE personal_access_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE personal_access_tokens FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON personal_access_tokens
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON personal_access_tokens
            USING (current_setting('app.is_admin', true) = 'true')
        ");

        // Same bootstrapping exception as users' own tenant_isolation_auth_lookup
        // (2026_07_17_145636_enable_row_level_security.php): SetTenantContext
        // has to resolve a bearer token's owning tenant BEFORE any tenant
        // context exists — PersonalAccessToken::findToken() has to be able to
        // find the row by its token hash across every tenant to even begin
        // that resolution. Scoped to personal_access_tokens alone, set
        // immediately before that one lookup and cleared immediately after
        // (see SetTenantContext::resolveTenantIdForToken()) — never left
        // active for anything else in the request.
        DB::statement("
            CREATE POLICY tenant_isolation_auth_lookup ON personal_access_tokens
            USING (current_setting('app.bypass_tenant_scope', true) = 'true')
        ");
    }
};
