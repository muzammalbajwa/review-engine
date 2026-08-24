<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7-day, no-card-required free trial. Deliberately lives on `tenants`, not
 * `subscriptions` — the whole point of this design is that trial state is
 * tracked entirely in our own DB, with no Stripe Customer/Subscription
 * created at trial start (see the Phase decision doc). `subscriptions`
 * stays exactly what Cashier expects: one row per *real* Stripe
 * subscription, created only at actual conversion (SubscriptionController
 * ::subscribe).
 *
 * `status` values: 'pending' (registered, hasn't picked a plan in
 * onboarding yet — the default), 'trialing' (picked a plan, trial running,
 * no card), 'active' (converted — real Stripe subscription exists),
 * 'trial_expired' (7 days passed, never converted), 'canceled' (reserved
 * for a future Stripe-cancellation sync; nothing transitions a tenant here
 * yet). Plain $table->enum(...), same convention as `type` on this same
 * table (2026_07_17_133906_create_tenants_table.php).
 *
 * `plan` is a plain nullable string, not an enum — validated at the
 * request layer against config('plans') keys (same as SubscribeRequest
 * already does), matching contacts.status/campaigns.type's existing
 * "app-level validation, not a DB constraint" convention so adding a
 * fourth plan tier later never needs a migration.
 *
 * Composite index on (status, trial_ends_at): the daily trial:expire
 * command's whole query is `WHERE status = 'trialing' AND trial_ends_at <=
 * now()` across every tenant — same "don't full-table-scan a scheduled
 * job" reasoning as every other tenant-table index in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan')->nullable()->after('onboarding_completed_at');
            $table->enum('status', ['pending', 'trialing', 'active', 'trial_expired', 'canceled'])
                ->default('pending')
                ->after('plan');
            $table->timestamp('trial_started_at')->nullable()->after('status');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');

            $table->index(['status', 'trial_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status', 'trial_ends_at']);
            $table->dropColumn(['plan', 'status', 'trial_started_at', 'trial_ends_at']);
        });
    }
};
