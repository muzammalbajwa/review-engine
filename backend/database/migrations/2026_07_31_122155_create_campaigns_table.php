<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * campaigns (.claude/DATABASE.md core tables). Minimal for now — just
 * enough for contacts.campaign_id (Phase 2 Step 1) and templates/
 * timing_rules (Phase 2 Step 3) to attach to. No CRUD endpoint yet; the
 * CSV import flow auto-provisions a tenant's first "live" campaign the same
 * way default compliant templates get auto-provisioned (.claude/ROADMAP.md:
 * "Most tenants never touch them").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type');
            $table->string('status');
            $table->timestamps();

            $table->index('tenant_id');
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON campaigns');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON campaigns');
        DB::statement('ALTER TABLE campaigns NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE campaigns DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('campaigns');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE campaigns ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE campaigns FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON campaigns
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON campaigns
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
