<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * messages — one row per (contact, step). .claude/QUEUE.md: "Idempotency:
 * unique job key per (contact_id, step). Never double-send." — enforced
 * here at the database level too (UNIQUE(tenant_id, contact_id, step)),
 * not just trusted to the job's own dispatch logic.
 *
 * click_token is the tracked redirect link's identifier (Phase 2 Step 4):
 * every contact gets the SAME review-request link/body for a given step —
 * this token identifies *who* clicked, never routes anyone to a different
 * destination based on anything about them. clicked_at/reviewed_at are
 * read by the step-3 job at send time (.claude/QUEUE.md: "The check lives
 * in the job, not the schedule") to suppress an already-resolved contact's
 * follow-up — a repetition guard, not a sentiment branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('status')->default('pending');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('click_token')->nullable()->unique();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'contact_id']);
            $table->unique(['tenant_id', 'contact_id', 'step']);
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON messages');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON messages');
        DB::statement('ALTER TABLE messages NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE messages DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('messages');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE messages FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON messages
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON messages
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
