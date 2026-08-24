<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * .claude/QUEUE.md: the tenant-facing counterpart to
 * GbpConnectionRevokedException — sent once per revocation (see
 * GbpTokenRefresher::markRevoked()'s revoked_alert_sent_at idempotency) so
 * a dead connection doesn't silently stop reviews without anyone noticing.
 *
 * Queued (unlike WorkerHeartbeatDown): this fires from an interactive
 * reply request as well as the background sync job, and there's no
 * chicken-and-egg problem here — a few seconds' delay sending this one
 * doesn't matter the way it would for "the queue itself might be dead."
 */
class GbpConnectionRevoked extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');

        return (new MailMessage)
            ->subject('Your Google Business Profile connection needs attention')
            ->line('Access to your Google Business Profile was revoked or has expired.')
            ->line("Reviews have stopped syncing and replies can't be posted until you reconnect.")
            ->action('Reconnect Google Business Profile', "{$frontendUrl}/gbp/connect");
    }
}
