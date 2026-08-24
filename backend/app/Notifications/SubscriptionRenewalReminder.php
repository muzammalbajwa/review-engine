<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * .claude/BILLING.md "Renewal reminders" — sent to every owner
 * (billing is owner-only, same audience GbpConnectionRevoked/
 * TeamPermissionsTest's own boundary already enforce) at the 10-day and
 * 5-day marks before a subscription's current_period_end. Content
 * branches on $autoRenew rather than being two separate notification
 * classes — unlike GbpConnectionRevoked/WorkerHeartbeatDown (one fixed
 * message each), this one genuinely varies along a second axis
 * (auto-renewing vs not) independent of which day threshold fired it,
 * and every constructor value is a primitive the caller (the
 * billing:send-renewal-reminders command) already computed — same
 * "pass primitives, not models" shape as TeamInviteReceived.
 */
class SubscriptionRenewalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $daysUntil,
        private readonly bool $autoRenew,
        private readonly Carbon $periodEnd,
        private readonly ?string $billingInterval,
        private readonly ?string $amountDisplay,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->periodEnd->toFormattedDateString();
        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');

        if ($this->autoRenew) {
            $amount = $this->amountDisplay ?? 'the plan price';
            $interval = $this->billingInterval ?? 'subscription';

            return (new MailMessage)
                ->subject("Your ReviewEngine plan renews in {$this->daysUntil} days")
                ->line("Your card will be charged {$amount} on {$date} for your {$interval} plan.")
                ->action('Manage billing', "{$frontendUrl}/settings?tab=billing");
        }

        return (new MailMessage)
            ->subject("Your ReviewEngine subscription ends in {$this->daysUntil} days")
            ->line("Your subscription ends on {$date} — renew now to keep sending review requests.")
            ->action('Renew now', "{$frontendUrl}/settings?tab=billing");
    }
}
