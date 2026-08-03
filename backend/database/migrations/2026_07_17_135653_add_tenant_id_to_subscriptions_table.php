<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cashier's own subscriptions table (user_id, stripe_id, stripe_status,
     * stripe_price, type) is left as-is — Billable stays on User, Cashier's
     * internals are untouched. This adds the tenant_id every tenant table
     * needs for the global scope + RLS layers (.claude/DATABASE.md), backed
     * by the owning user's tenant at creation time (see the Subscription
     * `creating` listener registered in AppServiceProvider).
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Postgres does not auto-index FK columns the way MySQL does.
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
