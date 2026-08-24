<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The public click-tracking redirect (GET /api/v1/click/{token}) resolves
 * a message — and from it, a tenant — from a bare click_token route
 * segment, with no tenant context active yet. Same bootstrapping problem
 * every other public-token lookup in this app already solves the same
 * way (ResolveQuickAddTenant's tenant_isolation_quick_add_lookup on
 * `tenants`, SetTenantContext's tenant_isolation_auth_lookup on `users`
 * and `personal_access_tokens`): a narrow policy keyed on
 * app.bypass_tenant_scope, active only around the one lookup-by-token
 * query, cleared immediately after.
 *
 * click_token is already high-entropy (Str::random(48), the same
 * generator/length as quick_add_token — ~285 bits) and already unique at
 * the column level (2026_08_03_150002_create_messages_table.php) — same
 * "not a guessable ID" guarantee, not new here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE POLICY tenant_isolation_click_lookup ON messages
            USING (current_setting('app.bypass_tenant_scope', true) = 'true')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_click_lookup ON messages');
    }
};
