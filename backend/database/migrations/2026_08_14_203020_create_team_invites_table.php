<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner invites a teammate by email; they don't have an account yet, so
 * this can't reuse sender_identities' signed-route verification (that
 * flips a boolean on an EXISTING row created by an authenticated request —
 * an invite has to bootstrap a brand-new User with no session at all).
 * Closer to quick_add_token's shape instead: a random, unguessable,
 * DB-stored token (Str::random(48)) looked up directly, not a
 * cryptographic signature.
 *
 * `token` is looked up with NO tenant context yet (same bootstrapping
 * problem SetTenantContext/AuthController::login() solve for `users`) — a
 * narrow tenant_isolation_invite_lookup policy below, keyed on the exact
 * same app.bypass_tenant_scope session variable those already use, reused
 * rather than inventing a second bypass flag.
 *
 * accepted_at/revoked_at (nullable timestamps), not a `status` enum column
 * — matches gbp_connections.revoked_alert_sent_at's existing
 * "nullable-timestamp-as-marker" convention on this codebase rather than a
 * fourth status-column shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 48)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'email']);
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON team_invites');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON team_invites');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_invite_lookup ON team_invites');
        DB::statement('ALTER TABLE team_invites NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE team_invites DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('team_invites');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE team_invites ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE team_invites FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON team_invites
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON team_invites
            USING (current_setting('app.is_admin', true) = 'true')
        ");

        // Same bootstrapping exception as users' own tenant_isolation_auth_lookup
        // (2026_07_17_145636_enable_row_level_security.php) and
        // personal_access_tokens' tenant_isolation_auth_lookup
        // (2026_08_06_133207_...): the invite-accept flow has to find this
        // row by its token alone, before any tenant is known at all. Set
        // immediately before that one lookup, cleared immediately after
        // (TeamInviteController), never left active for anything else.
        DB::statement("
            CREATE POLICY tenant_isolation_invite_lookup ON team_invites
            USING (current_setting('app.bypass_tenant_scope', true) = 'true')
        ");
    }
};
