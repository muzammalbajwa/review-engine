<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .claude/BILLING.md "Renewal reminders": idempotency for
 * billing:send-renewal-reminders. Stores the DATE of the subscription's
 * current_period_end each reminder was already sent for — not a plain
 * boolean/timestamp — because current_period_end moves forward every
 * billing cycle (App\Models\Subscription::periodEnd(), backed by
 * renews_at/ends_at). Comparing against the stored date rather than just
 * "has this ever been sent" is what makes the reminder correctly re-fire
 * next cycle with zero extra bookkeeping: once renews_at/ends_at rolls
 * forward, the stored date here no longer matches the newly computed
 * period end, so the command's "already sent for this period?" check
 * naturally reads false again — no explicit reset hook needed anywhere
 * (contrast gbp_connections.revoked_alert_sent_at, which needs an
 * explicit reset on reconnect because that flag has no natural
 * "which cycle" dimension to compare against).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lemon_squeezy_subscriptions', function (Blueprint $table) {
            $table->date('renewal_reminder_10d_sent_for')->nullable()->after('ends_at');
            $table->date('renewal_reminder_5d_sent_for')->nullable()->after('renewal_reminder_10d_sent_for');
        });
    }

    public function down(): void
    {
        Schema::table('lemon_squeezy_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['renewal_reminder_10d_sent_for', 'renewal_reminder_5d_sent_for']);
        });
    }
};
