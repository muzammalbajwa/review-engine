<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * .claude/QUEUE.md: "Heartbeat alert if the worker dies — a silent worker
 * means every tenant's reviews stop." Sent by CheckWorkerHeartbeat when
 * Horizon's master supervisor is missing or paused.
 *
 * Deliberately NOT queued (no ShouldQueue): if the queue worker is exactly
 * what's dead, dispatching this notification onto that same dead queue
 * would mean it never sends — it has to go out synchronously, on the
 * scheduler's own process, right when the outage is detected.
 */
class WorkerHeartbeatDown extends Notification
{
    use Queueable;

    public function __construct(public readonly string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[ReviewEngine] Queue worker heartbeat failed')
            ->line("The Horizon queue worker heartbeat check failed: {$this->reason}")
            ->line('Every tenant\'s review requests, sync, and replies stop silently while this is down.')
            ->line('Check Horizon (php artisan horizon:status) and restart the worker process immediately.');
    }
}
