<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Lemon Squeezy -> Paddle processor swap (removal step; see the
 * lemonsqueezy/laravel package removal and LemonSqueezyWebhookController
 * deletion in this same change). Same precedent as
 * 2026_08_13_151025_drop_cashier_stripe_billing_schema.php set for the
 * Stripe -> Lemon Squeezy swap: once the package is gone, its own tables
 * (lemon_squeezy_customers, lemon_squeezy_subscriptions — including the
 * tenant_id/RLS/renewal-reminder columns this app added on top of them)
 * are dead weight, not data to migrate in place.
 *
 * Confirmed via direct psql query against this environment's own local
 * DB before writing this migration: zero rows in either table, zero
 * tenants anywhere with a non-null `plan`. There is no real subscription
 * data this drops locally.
 *
 * This does NOT touch `tenants.plan`/`status`/`billing_interval`/
 * `trial_started_at`/`trial_ends_at` — those columns are provider-
 * agnostic (the trial/status state machine lives entirely on `tenants`,
 * never on a billing object) and stay exactly as they are for whatever
 * processor gets wired up next.
 *
 * Staging/production: NOT verified from this environment (no reachable
 * staging DB from here). Whoever runs this migration against staging
 * must first confirm there's no real subscriber data there — same check
 * this docblock records for local, and the same check
 * drop_cashier_stripe_billing_schema.php's docblock recorded for the
 * Stripe drop. If real Lemon Squeezy subscribers exist in a real
 * environment when this ships, they need to be handled explicitly before
 * this migration runs there — this migration does not attempt any
 * data migration to a new processor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lemon_squeezy_subscriptions');
        Schema::dropIfExists('lemon_squeezy_customers');
    }

    public function down(): void
    {
        // Deliberately not recreated here — same reasoning as
        // drop_cashier_stripe_billing_schema.php's down(): this processor
        // swap is not meant to be rolled back live. Restore from a
        // pre-migration backup instead.
    }
};
