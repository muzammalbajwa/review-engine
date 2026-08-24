<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding resumability (.claude/FRONTEND.md guided flow). Deliberately
 * minimal: subscribed/GBP-connected/contacts-imported are already live,
 * derivable facts (subscriptions table, gbp_connections.status,
 * contacts count) — re-deriving them, not tracking a duplicate copy, is
 * what the dashboard's "still incomplete" flags read from later.
 *
 * The two `_step_done` flags exist only because that derivation breaks
 * for one specific case: a tenant who explicitly skips connecting GBP (or
 * importing contacts) still needs the wizard to move on and never ask
 * again this run, even though the underlying fact ("not connected" /
 * "zero contacts") hasn't changed. Without these, re-visiting /onboarding
 * mid-flow would re-force a step the tenant already declined.
 *
 * onboarding_completed_at is the one signal that gates whether /onboarding
 * shows the wizard at all vs. redirecting straight to /dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('gbp_step_done')->default(false)->after('type');
            $table->boolean('contacts_step_done')->default(false)->after('gbp_step_done');
            $table->timestamp('onboarding_completed_at')->nullable()->after('contacts_step_done');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['gbp_step_done', 'contacts_step_done', 'onboarding_completed_at']);
        });
    }
};
