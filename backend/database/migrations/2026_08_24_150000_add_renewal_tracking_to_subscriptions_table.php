<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things Cashier Paddle's own `subscriptions` table (published
 * unmodified — 2026_08_24_130001_create_subscriptions_table.php) doesn't
 * carry, both needed for the auto-renew toggle and renewal-reminder
 * emails:
 *
 * `renews_at` — Paddle's `next_billed_at` (present on every subscription
 * webhook payload) is read by Cashier's own handleSubscriptionCreated()
 * ONLY to set `trial_ends_at` while trialing, then discarded — nothing in
 * the package persists a general "when does this renew" fact.
 * PaddleWebhookController::syncRenewalDate() writes it here on every
 * subscription.created/updated. Confirmed against the real Subscription
 * class (vendor/laravel/cashier-paddle/src/Subscription.php): `cancel()`
 * doesn't touch `status` (it stays 'active' during a cancel-at-period-end
 * notice window, matching the STATUS_MAP docblock's own reasoning) — it
 * writes `ends_at`, and `stopCancelation()` (the real "undo a scheduled
 * cancel" method — NOT `resume()`, which is for undoing a *pause*, a
 * different Paddle mechanism entirely) clears it back to null. So
 * App\Models\Subscription::periodEnd() below can key off
 * `ends_at ?? renews_at` exactly the way the previous processor's design
 * did off its own `ends_at`/`renews_at` pair — same semantics, real
 * Paddle-specific methods underneath.
 *
 * `renewal_reminder_{10,5}d_sent_for` — same idempotency shape and same
 * reasoning as the previous processor's identically-named columns (see
 * git history's add_renewal_reminder_tracking_to_lemon_squeezy_
 * subscriptions_table.php docblock for the full "why a date column, not
 * a lock or a boolean" reasoning, unchanged by the processor swap):
 * current_period_end moves forward every cycle, so a reminder must
 * re-fire each cycle — storing the date the reminder was sent *for*
 * naturally makes next cycle's reminder fire correctly with no explicit
 * reset hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('renews_at')->nullable()->after('ends_at');
            $table->date('renewal_reminder_10d_sent_for')->nullable()->after('renews_at');
            $table->date('renewal_reminder_5d_sent_for')->nullable()->after('renewal_reminder_10d_sent_for');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['renews_at', 'renewal_reminder_10d_sent_for', 'renewal_reminder_5d_sent_for']);
        });
    }
};
