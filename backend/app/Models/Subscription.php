<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Laravel\Paddle\Subscription as PaddleSubscription;

/**
 * Extends laravel/cashier-paddle's own Subscription model (registered via
 * Cashier::useSubscriptionModel() in AppServiceProvider) purely to attach
 * BelongsToTenant — same reasoning as App\Models\Customer. The package's
 * real columns/behavior (paddle_id, status, the billable morph,
 * trial_ends_at/paused_at/ends_at, and its own cancel()/stopCancelation()/
 * pause()/resume() API methods) are untouched.
 *
 * tenant_id auto-fills from CurrentTenant — for the one place this app
 * ever creates a Subscription row, that's always inside
 * PaddleWebhookController's tenant-context-resolved transaction (Cashier's
 * own handleSubscriptionCreated(), called via parent::__invoke() —
 * see that controller's docblock for how tenant_id gets resolved and set
 * *before* delegating).
 */
class Subscription extends PaddleSubscription
{
    use BelongsToTenant;

    /**
     * Settings/Billing's "auto-renew" toggle state — deliberately NOT
     * `$this->active()`: Paddle keeps `status = 'active'` through a
     * cancel-at-period-end's entire notice window (confirmed against the
     * real Subscription::cancel() source — it writes `ends_at`, not
     * `status`), so `status` alone can't answer "will this renew." A
     * scheduled cancellation clears `ends_at` back to null via
     * stopCancelation() (SubscriptionController::update()) or resolves it
     * via a real cancellation reaching `status = 'canceled'`
     * (PaddleWebhookController's STATUS_MAP) — either way, `ends_at` being
     * set is exactly "will not renew," Paddle's own paused()/canceled()
     * cover the other two non-renewing states.
     */
    public function autoRenews(): bool
    {
        return $this->ends_at === null && ! $this->paused() && ! $this->canceled();
    }

    /**
     * The date Settings/Billing shows next to the toggle: the next charge
     * date while auto-renewing, or the date access actually ends once
     * cancel-at-period-end has been requested. Never both at once — Paddle
     * clears `next_billed_at` (synced into `renews_at` by
     * PaddleWebhookController::syncRenewalDate()) once a subscription is
     * no longer actively renewing, the same way the previous processor's
     * `renews_at` behaved.
     */
    public function periodEnd(): ?CarbonInterface
    {
        return $this->ends_at ?? $this->renews_at;
    }

    /**
     * Overrides (not replaces) the package's own casts — see
     * HasAttributes::initializeHasAttributes(), which merges $this->casts
     * (the parent's own array, defining trial_ends_at/paused_at/ends_at,
     * untouched) with $this->casts() (this method). Redeclaring $casts
     * directly here would shadow the parent's array entirely and silently
     * drop those three casts this whole class depends on.
     */
    protected function casts(): array
    {
        return [
            'renews_at' => 'datetime',
            'renewal_reminder_10d_sent_for' => 'date',
            'renewal_reminder_5d_sent_for' => 'date',
        ];
    }

    /**
     * billing:send-renewal-reminders' idempotency check — see the
     * add_renewal_tracking_to_subscriptions_table migration's own
     * docblock for why this compares against the *current* period end
     * rather than just "has this ever been sent."
     */
    public function renewalReminderAlreadySentFor(int $days, CarbonInterface $periodEnd): bool
    {
        $column = $this->reminderColumnFor($days);
        $sentFor = $this->{$column};

        return $sentFor !== null && $sentFor->isSameDay($periodEnd);
    }

    public function markRenewalReminderSentFor(int $days, CarbonInterface $periodEnd): void
    {
        $column = $this->reminderColumnFor($days);
        $this->{$column} = $periodEnd->toDateString();
        $this->save();
    }

    private function reminderColumnFor(int $days): string
    {
        return match ($days) {
            10 => 'renewal_reminder_10d_sent_for',
            5 => 'renewal_reminder_5d_sent_for',
            default => throw new \InvalidArgumentException("No renewal reminder column for {$days} days."),
        };
    }
}
