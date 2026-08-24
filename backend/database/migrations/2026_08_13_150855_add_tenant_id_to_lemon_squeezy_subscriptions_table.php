<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same pattern as 2026_07_17_135653_add_tenant_id_to_subscriptions_table.php
 * did for Cashier's subscriptions table — see that migration and
 * add_tenant_id_to_lemon_squeezy_customers_table's docblocks. Backed by the
 * owning User's tenant at creation time via App\Models\Subscription's
 * BelongsToTenant, which only resolves correctly because
 * LemonSqueezyWebhookController explicitly sets tenant context before the
 * package's webhook handler creates/syncs this row (see that controller).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lemon_squeezy_subscriptions', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('lemon_squeezy_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
