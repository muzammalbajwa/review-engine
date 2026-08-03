<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gbp_connections (.claude/DATABASE.md core tables: "oauth_token
 * [encrypted]"). Expanded from the doc's single oauth_token field to what a
 * working OAuth2 flow actually needs: an access token AND a refresh token
 * (the refresh token is the one that matters long-term — access tokens
 * expire in ~1 hour), both encrypted at rest via GbpConnection's encrypted
 * casts, never in plaintext in this table or in any log.
 *
 * `status` isn't in the doc's schema either — added for Phase 3 Step 4
 * ("handle and surface OAuth token expiry/revocation gracefully"), so a
 * revoked connection has somewhere to record that fact instead of the
 * tenant just silently stopping receiving review syncs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->text('oauth_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at');
            $table->string('location_id');
            $table->string('review_link')->nullable();
            $table->string('status')->default('connected');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON gbp_connections');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON gbp_connections');
        DB::statement('ALTER TABLE gbp_connections NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE gbp_connections DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('gbp_connections');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE gbp_connections ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE gbp_connections FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON gbp_connections
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON gbp_connections
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
