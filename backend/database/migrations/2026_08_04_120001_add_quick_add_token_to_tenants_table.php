<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The unauthenticated quick-add link (reviewengine.com/quick/{token})
 * needs to resolve a tenant from a bare URL segment before any tenant
 * context exists — same bootstrapping problem AuthController's
 * login/register lookups and SetTenantContext's own token resolution
 * solve, and the same fix: a narrow, single-purpose RLS bypass, active
 * only around the one lookup query that needs it.
 *
 * `app.bypass_tenant_scope` already exists for exactly this class of
 * problem (2026_07_17_145636_enable_row_level_security.php, currently
 * scoped to `users` alone for the login lookup) — reused here by name
 * rather than inventing a second flag, since it's the same concept
 * ("resolve identity before tenant context exists"), just needed on a
 * second table now. Not `app.is_admin`: that flag means real,
 * audited cross-tenant admin access, which this isn't.
 *
 * `quick_add_token` is high-entropy (Str::random(48) ~ 285 bits) and
 * never guessable by construction — the "not a guessable ID" requirement
 * is met by the token's entropy, not by keeping it secret from RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('quick_add_token', 64)->nullable()->unique()->after('type');
        });

        // Backfill: the model's creating() hook (added alongside this
        // migration) only fires for tenants created from here on —
        // existing rows need a real token too, generated once now.
        // Migrations run as the Postgres owner role (superuser), which
        // bypasses RLS unconditionally regardless of FORCE ROW LEVEL
        // SECURITY, so no bypass flag is needed for this bulk update.
        DB::table('tenants')->select('id')->orderBy('id')->get()->each(function ($tenant) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'quick_add_token' => Str::random(48),
            ]);
        });

        DB::statement("
            CREATE POLICY tenant_isolation_quick_add_lookup ON tenants
            USING (current_setting('app.bypass_tenant_scope', true) = 'true')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_quick_add_lookup ON tenants');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('quick_add_token');
        });
    }
};
