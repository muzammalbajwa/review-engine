<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * timing_rules — one row per tenant (unique on tenant_id): the delay
 * before each follow-up step, and the local business-hours window the
 * reactivation drip (Phase 2 Step 5) must never release outside of.
 * business_hours_start/end are plain `time` columns (a clock time, not a
 * duration) interpreted in `timezone` — .claude/QUEUE.md: "Timezone is
 * per-tenant... Never blast."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('delay_minutes_step2');
            $table->unsignedInteger('delay_minutes_step3');
            $table->time('business_hours_start');
            $table->time('business_hours_end');
            $table->string('timezone');
            $table->timestamps();

            $table->unique('tenant_id');
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON timing_rules');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON timing_rules');
        DB::statement('ALTER TABLE timing_rules NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE timing_rules DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('timing_rules');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE timing_rules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE timing_rules FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON timing_rules
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON timing_rules
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
