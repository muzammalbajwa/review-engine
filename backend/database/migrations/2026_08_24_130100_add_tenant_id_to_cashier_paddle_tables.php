<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same pattern as add_tenant_id_to_lemon_squeezy_{customers,subscriptions}
 * _table.php did for the previous processor: laravel/cashier-paddle's own
 * migrations (2026_08_24_130000-130003, published unmodified via
 * `vendor:publish --tag=cashier-migrations`, only renamed from their
 * original 2019 timestamps) are left untouched — this adds the tenant_id
 * every tenant table needs for the global scope + RLS layers
 * (.claude/DATABASE.md, CLAUDE.md golden rule #2).
 *
 * `customers`, `subscriptions`, and `transactions` each carry their own
 * billable morph (billable_id/billable_type) — tenant_id will resolve
 * from whichever model ends up Billable, the same way it did for the
 * previous processor's Customer/Subscription. `subscription_items` has no
 * morph of its own (it only has subscription_id, a plain FK) — it still
 * gets a direct tenant_id column here rather than relying on a join
 * through its parent subscription, matching how every other RLS'd
 * child-of-a-tenant-row table in this app works (e.g. messages,
 * timing_rules): an RLS policy needs a column on the table itself to
 * check, and a table holding tenant data is never left to inherit
 * isolation indirectly. How tenant_id actually gets populated on these
 * four tables (at checkout/webhook time) is deliberately not built yet —
 * this is schema only, ahead of that logic, so nothing can land in these
 * tables un-scoped from the moment they exist.
 */
return new class extends Migration
{
    private const TABLES = ['customers', 'subscriptions', 'subscription_items', 'transactions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignUuid('tenant_id')
                    ->after('id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();

                // Postgres does not auto-index FK columns the way MySQL does.
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
    }
};
