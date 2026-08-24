<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product-tour progress (dashboard nav walkthrough now, contextual
 * per-screen tours later). Deliberately on `users`, not `tenants`: a
 * tenant with multiple team members needs each person's own "have I seen
 * this" state, not one shared flag for the whole business — the same
 * isolation model as everything else on this table (tenant_id + RLS),
 * just at the individual level instead. Also deliberately server-side,
 * not localStorage: a user switching devices or clearing browser data
 * shouldn't see a tour they've already completed re-trigger.
 *
 * Two fields, matching two different shapes of "seen":
 *  - has_completed_welcome_tour: the one first-login dashboard/nav
 *    walkthrough. A dedicated boolean because there's only ever one of
 *    these, and it's the field the auto-launch check reads on every
 *    /dashboard visit.
 *  - tours_seen: a JSON map of contextual, per-screen tour keys ->
 *    seen. Plural and open-ended on purpose — this migration ships
 *    before any per-screen tour content exists (see BILLING.md-style
 *    decision docs elsewhere in this app: plumbing before content), so
 *    the set of valid keys isn't fixed yet. TourController constrains
 *    key *format* (lowercase, alphanumeric/underscore/hyphen, capped
 *    length) rather than an enum, so shipping a new per-screen tour
 *    later never needs a migration or a whitelist edit here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_completed_welcome_tour')->default(false)->after('role');
            $table->jsonb('tours_seen')->default('{}')->after('has_completed_welcome_tour');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['has_completed_welcome_tour', 'tours_seen']);
        });
    }
};
