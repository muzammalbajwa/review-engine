<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same pattern as 2026_07_17_135653_add_tenant_id_to_subscriptions_table.php
 * did for Cashier's subscriptions table: the package's own migration
 * (lemonsqueezy/laravel's create_customers_table) is left untouched, this
 * adds the tenant_id every tenant table needs for the global scope + RLS
 * layers (.claude/DATABASE.md). Backed by the owning User's tenant at
 * creation time via App\Models\Customer's BelongsToTenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lemon_squeezy_customers', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('lemon_squeezy_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
