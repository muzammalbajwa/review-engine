<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe -> Lemon Squeezy processor swap (.claude/BILLING.md). This is a
 * genuine replacement, not parallel operation — `subscriptions`/
 * `subscription_items` (Cashier's own tables, tenant_id + RLS added by
 * 2026_07_17_135653) and the four Cashier columns on `users`
 * (2026_07_17_122241_create_customer_columns) are dead weight the moment
 * laravel/cashier is removed from composer.json. Confirmed via
 * `php artisan tinker` against this environment's own DB before writing
 * this migration: zero tenants exist anywhere with a non-null `plan`, so
 * there is no real subscription data this drops — see BILLING.md's
 * "existing tenants" section for what happens if that's ever not true in
 * a real environment before this runs.
 *
 * Postgres DROP TABLE implicitly drops that table's own RLS policies,
 * indexes, and constraints — no separate policy cleanup needed first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        // Recreating `subscriptions`/`subscription_items` with their exact
        // original columns, tenant_id, and RLS policies is out of scope for
        // a down() migration — this processor swap is not meant to be
        // rolled back live. Restore from a pre-migration backup instead.
    }
};
