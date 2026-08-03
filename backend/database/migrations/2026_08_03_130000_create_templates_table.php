<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * templates (.claude/DATABASE.md core tables: "templates (id, tenant_id,
 * campaign_id, step[1|2|3], body, compliance_status)"). Expanded with:
 *  - compliance_reasons / suggested_rewrite: .claude/COMPLIANCE.md's
 *    checker returns "{status: pass|block, reasons: [...],
 *    suggested_rewrite: '...'}" — compliance_status alone doesn't carry
 *    the "friendly fix" the doc requires surfacing back to the tenant.
 *  - UNIQUE (tenant_id, campaign_id, step): one live template per step per
 *    campaign, upserted in place — same one-row-per-owner pattern as
 *    GbpConnection/Reply, not a history table.
 *
 * compliance_status only ever reaches 'pass' here: a save that fails the
 * checker is rejected before it's ever written (.claude/CLAUDE.md golden
 * rule #4: "No message template can be saved without passing the
 * compliance checker") — see TemplateController. The column still exists
 * (rather than being assumed) because it's what a future consumer (the
 * Phase 2 drip engine) checks before using a template, not just a record
 * of the save-time decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->text('body');
            $table->string('compliance_status')->default('pass');
            $table->json('compliance_reasons')->nullable();
            $table->text('suggested_rewrite')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'campaign_id', 'step']);
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON templates');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON templates');
        DB::statement('ALTER TABLE templates NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE templates DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('templates');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE templates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE templates FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON templates
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON templates
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
