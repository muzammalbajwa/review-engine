<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * replies (.claude/DATABASE.md core tables: "replies (id, tenant_id,
 * review_id, body, posted_at, policy_violation)"). Expanded with:
 *  - policy_violation_reason: .claude/COMPLIANCE.md says to "Surface
 *    Google's PolicyViolation status on rejected auto-replies so the
 *    customer sees when Google refused a reply" — a bare boolean doesn't
 *    tell the tenant *why*, only Google's own message does.
 *  - UNIQUE (tenant_id, review_id): one reply record per review. A retry
 *    after a policy-violation rejection updates this same row rather than
 *    creating a second one, mirroring GbpConnection's firstOrNew-by-owner
 *    pattern.
 *  - posted_at nullable: null means "drafted, not yet successfully posted"
 *    (either not attempted or rejected) — set only once Google actually
 *    accepts the reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replies', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('posted_at')->nullable();
            $table->boolean('policy_violation')->default(false);
            $table->text('policy_violation_reason')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'review_id']);
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON replies');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON replies');
        DB::statement('ALTER TABLE replies NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE replies DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('replies');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE replies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE replies FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON replies
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON replies
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
