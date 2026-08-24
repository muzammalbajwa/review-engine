<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use LemonSqueezy\Laravel\Subscription as LemonSqueezySubscription;

/**
 * Extends the Lemon Squeezy package's own Subscription model (registered via
 * LemonSqueezy::useSubscriptionModel() in AppServiceProvider) purely to
 * attach BelongsToTenant. The package's real columns/behavior
 * (lemon_squeezy_id, status, variant_id, the billable morph) are untouched.
 *
 * tenant_id auto-fills from CurrentTenant — for a normal, authenticated
 * POST /subscribe request that's already true by the time a checkout is
 * created, and for a webhook-driven write (the actual create/sync of this
 * row, which always happens from LemonSqueezyWebhookController) that
 * controller explicitly resolves tenant_id from the checkout's own
 * custom_data and sets tenant context before delegating to the package's
 * webhook processing — see that controller's docblock.
 */
class Subscription extends LemonSqueezySubscription
{
    use BelongsToTenant;

    /**
     * Settings/Billing's "auto-renew" toggle state — deliberately NOT a
     * stored column (.claude/BILLING.md's "Auto-renew toggle" section):
     * Lemon Squeezy's own `status`/`ends_at` already carry this fact.
     * `cancelled()` here means "cancel-at-period-end has been requested" —
     * the package's onGracePeriod() (cancelled() && ends_at is future)
     * still returns true right up to ends_at, so this reads false the
     * instant a cancellation is requested, not just once access actually
     * ends. That's the correct "will this renew" semantics for a toggle;
     * see Tenant::sendingBlocked() for the separate "has access actually
     * ended yet" question, which does key off ends_at/the expired webhook.
     */
    public function autoRenews(): bool
    {
        return ! $this->cancelled() && ! $this->paused() && ! $this->expired();
    }

    /**
     * The date Settings/Billing shows next to the toggle: the next charge
     * date while auto-renewing, or the date access actually ends once
     * cancel-at-period-end has been requested. Never both at once — Lemon
     * Squeezy clears renews_at when a subscription is cancelled.
     */
    public function periodEnd(): ?CarbonInterface
    {
        return $this->cancelled() ? $this->ends_at : $this->renews_at;
    }

    /**
     * Overrides (not replaces) the package's own $casts property — see
     * HasAttributes::initializeHasAttributes(), which merges $this->casts
     * (the inherited property, untouched) with $this->casts() (this
     * method). Redeclaring $casts directly here would shadow the parent's
     * array entirely and silently drop the renews_at/ends_at/etc. casts
     * this whole class depends on.
     */
    protected function casts(): array
    {
        return [
            'renewal_reminder_10d_sent_for' => 'date',
            'renewal_reminder_5d_sent_for' => 'date',
        ];
    }

    /**
     * billing:send-renewal-reminders' idempotency check — see the
     * migration's own docblock for why this compares against the
     * *current* period end rather than just "has this ever been sent."
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
