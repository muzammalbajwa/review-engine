<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs (.claude/DATABASE.md core tables list). tenant_id is nullable
 * here, unlike every other tenant table — some admin actions (e.g. "list
 * all tenants") don't have a single target tenant to attribute the row to;
 * those rows get tenant_id = null and rely on actor_id + action + target
 * instead. RLS + the admin bypass policy (see enableRls()) are the same
 * two-layer pattern the 2026_07_17_145636 migration already established for
 * users/subscriptions/tenants — this table gets app_user's default grants
 * automatically via that migration's ALTER DEFAULT PRIVILEGES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->string('target');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_id');
            $table->index('actor_id');
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON audit_logs');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON audit_logs');
        DB::statement('ALTER TABLE audit_logs NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_logs DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('audit_logs');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY');

        // Every tenant can read their own audit rows. A NULL tenant_id row
        // (a cross-all-tenants admin action) is deliberately invisible under
        // this policy — only tenant_isolation_admin can see those.
        DB::statement("
            CREATE POLICY tenant_isolation ON audit_logs
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON audit_logs
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
