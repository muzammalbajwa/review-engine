<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * .claude/QUEUE.md: "Failed jobs go to failed_jobs table + alert." — same
 * shape as WorkerHeartbeatDown (same config('services.ops.alert_email'),
 * same "don't fail silently" reasoning), sent from SendReviewRequest's
 * failing() once retries are exhausted. Unlike the heartbeat alert this
 * is about one tenant's one contact, not a systemic outage, so it's
 * queued normally rather than forced synchronous.
 */
class ReviewRequestSendFailed extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $contactId,
        public readonly int $step,
        public readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[ReviewEngine] A review request failed to send')
            ->line("Step {$this->step} failed to send to contact #{$this->contactId} (tenant {$this->tenantId}) after exhausting retries.")
            ->line("Reason: {$this->reason}")
            ->line('It is now in failed_jobs — check the admin system dashboard or requeue it once the underlying issue is fixed.');
    }
}
