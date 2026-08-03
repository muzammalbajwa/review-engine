<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * reviews (.claude/DATABASE.md core tables: "reviews (id, tenant_id,
 * google_review_id, rating, text, created_at)"). Expanded with:
 *  - gbp_connection_id: which connection synced this (a tenant could
 *    reasonably reconnect a different Google account later).
 *  - google_review_id UNIQUE PER TENANT: the idempotency key for sync — a
 *    review can never be inserted twice by re-running the sync job.
 *  - needs_reply: set true on insert for every newly-synced review, false
 *    once a reply is posted (Phase 3 Step 3). Doing this as a column rather
 *    than "no matching row in replies" keeps the "what needs my attention"
 *    query a plain indexed lookup, not a join, for what's meant to run on
 *    every sync tick.
 *  - reviewer_name/review_created_at: Google's own review fields, not
 *    invented — needed to actually show a review meaningfully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_connection_id')->constrained('gbp_connections')->cascadeOnDelete();
            $table->string('google_review_id');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('text')->nullable();
            $table->string('reviewer_name')->nullable();
            $table->timestamp('review_created_at')->nullable();
            $table->boolean('needs_reply')->default(true);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'needs_reply']);
            $table->unique(['tenant_id', 'google_review_id']);
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON reviews');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON reviews');
        DB::statement('ALTER TABLE reviews NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE reviews DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('reviews');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE reviews ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE reviews FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON reviews
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON reviews
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
