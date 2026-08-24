<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminder;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * .claude/BILLING.md "Renewal reminders": at 10 and 5 days before a real
 * subscription's current_period_end (App\Models\Subscription::periodEnd()
 * — renews_at while auto-renewing, ends_at once cancel-at-period-end has
 * been requested), notify every tenant owner by email. Content depends on
 * autoRenews(): a courtesy notice ("your card will be charged") vs an
 * action-needed notice ("renew now to keep sending"). Same daily cadence
 * and structure as trial:expire — a bulk, RLS-bypassed read of candidate
 * ids, then a per-row re-verify-and-write inside that row's own tenant
 * context — since this is the same shape of "date crossed a threshold,
 * flip something" problem trial:expire already solves, just watching
 * current_period_end instead of trial_ends_at.
 *
 * Paddle's own STATUS_CANCELED (American spelling — confirmed against
 * Laravel\Paddle\Subscription's real constants) here, not the previous
 * processor's STATUS_CANCELLED.
 *
 * Idempotency: renewal_reminder_{10,5}d_sent_for (see that migration's
 * docblock) — set to periodEnd()'s date once a reminder actually sends,
 * checked before sending again. This is NOT the same "no lock needed"
 * case trial:expire is: flipping tenants.status twice is a true no-op,
 * but sending SubscriptionRenewalReminder twice is a real duplicate email
 * — a non-idempotent side effect, the same class of problem
 * drip:release-pending's Cache::lock exists to prevent for dispatching
 * SendReviewRequest twice. Unlike drip's per-tenant *batch selection*
 * race across many contact rows, this race is scoped to one subscription
 * row's own read-then-write — a plain `lockForUpdate()` inside the
 * per-row transaction that's already being opened closes it completely
 * (two concurrent transactions serialize on that row at the Postgres
 * level; the second sees the first's committed sent_for value and skips)
 * without reaching for a separate Redis-backed Cache::lock built for a
 * higher-contention, multi-row problem this command doesn't have. The
 * realistic collision is the same one ReleasePendingContacts's docblock
 * describes: a manual `php artisan billing:send-renewal-reminders`
 * racing the daily scheduled tick — low-frequency enough that a plain row
 * lock (which blocks briefly rather than failing fast) is the right
 * amount of machinery, not too little, not drip's heavier tool.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'billing:send-renewal-reminders';

    protected $description = 'Email tenant owners at 10 and 5 days before their subscription current_period_end.';

    private const REMINDER_DAYS = [10, 5];

    public function handle(): int
    {
        $sent = 0;

        foreach (self::REMINDER_DAYS as $days) {
            $sent += $this->sendForThreshold($days);
        }

        $this->info("Sent {$sent} renewal reminder(s).");

        return self::SUCCESS;
    }

    private function sendForThreshold(int $days): int
    {
        $targetDate = today()->addDays($days);
        $candidates = $this->candidateSubscriptions($days, $targetDate);

        $sent = 0;

        foreach ($candidates as $candidate) {
            if ($this->sendReminderForSubscription($candidate->id, $candidate->tenant_id, $days, $targetDate)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Bypasses RLS deliberately, scoped to exactly this one read — same
     * pattern as ExpireStaleTrials::staleTrialTenantIds(). Two shapes of
     * "current_period_end" exist (App\Models\Subscription::periodEnd()'s
     * own conditional), so two queries rather than one: an actively
     * renewing subscription's relevant date is renews_at, a
     * cancel-at-period-end subscription's is ends_at — never both at
     * once, matching periodEnd()'s own "never both" guarantee.
     */
    private function candidateSubscriptions(int $days, Carbon $targetDate): iterable
    {
        return DB::transaction(function () use ($targetDate) {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            $renewing = Subscription::withoutGlobalScopes()
                ->where('status', Subscription::STATUS_ACTIVE)
                ->whereDate('renews_at', $targetDate)
                ->get(['id', 'tenant_id']);

            $endingGracePeriod = Subscription::withoutGlobalScopes()
                ->where('status', Subscription::STATUS_CANCELED)
                ->whereDate('ends_at', $targetDate)
                ->get(['id', 'tenant_id']);

            return $renewing->concat($endingGracePeriod);
        });
    }

    /**
     * Re-verified against live state inside its own transaction rather
     * than trusted from the bulk read above — same "don't trust a stale
     * batch read for the actual mutation" reasoning as
     * ExpireStaleTrials::expireTenant()/ReleasePendingContacts's own
     * docblocks. periodEnd() is recomputed from the freshly-locked row,
     * not passed in from the bulk query, so a status change between the
     * bulk read and here (e.g. a cancellation webhook landing in
     * between) is reflected correctly rather than acting on stale
     * status/date combination.
     */
    private function sendReminderForSubscription(int $subscriptionId, string $tenantId, int $days, Carbon $targetDate): bool
    {
        return DB::transaction(function () use ($subscriptionId, $tenantId, $days, $targetDate) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
            app(CurrentTenant::class)->set($tenantId);

            try {
                $subscription = Subscription::query()->lockForUpdate()->find($subscriptionId);

                if ($subscription === null) {
                    return false;
                }

                $periodEnd = $subscription->periodEnd();

                if ($periodEnd === null || ! $periodEnd->isSameDay($targetDate)) {
                    // Moved since the bulk read (renewed, resumed,
                    // canceled) — no longer actually at this threshold.
                    return false;
                }

                if ($subscription->renewalReminderAlreadySentFor($days, $periodEnd)) {
                    return false;
                }

                $tenant = Tenant::query()->find($tenantId);

                if ($tenant === null) {
                    return false;
                }

                $subscription->markRenewalReminderSentFor($days, $periodEnd);

                $this->notifyOwners($tenant, $subscription, $days, $periodEnd);

                Log::info('billing:send-renewal-reminders sent a reminder', [
                    'tenant_id' => $tenantId,
                    'days_until' => $days,
                    'period_end' => $periodEnd->toDateString(),
                    'auto_renew' => $subscription->autoRenews(),
                ]);

                return true;
            } finally {
                app(CurrentTenant::class)->clear();
            }
        });
    }

    private function notifyOwners(Tenant $tenant, Subscription $subscription, int $days, Carbon $periodEnd): void
    {
        $priceCents = $tenant->billing_interval !== null
            ? config("plans.standard.intervals.{$tenant->billing_interval}.price_cents")
            : null;

        $notification = new SubscriptionRenewalReminder(
            daysUntil: $days,
            autoRenew: $subscription->autoRenews(),
            periodEnd: $periodEnd,
            billingInterval: $tenant->billing_interval,
            amountDisplay: $priceCents !== null ? '$'.number_format($priceCents / 100, 0) : null,
        );

        User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->get()
            ->each(fn (User $owner) => $owner->notify($notification));
    }
}
