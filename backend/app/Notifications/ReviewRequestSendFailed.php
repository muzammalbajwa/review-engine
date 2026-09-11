<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * .claude/QUEUE.md: "Failed jobs go to failed_jobs table + alert." — same
 * shape as WorkerHeartbeatDown (same config('services.ops.alert_email'),
 * same "don't fail silently" reasoning), sent from SendReviewRequest's
 * failed() once retries (or QA-audit Finding 5's new MAX_SKIP_RETRIES
 * give-up) are exhausted. Unlike the heartbeat alert this is about one
 * tenant's one contact, not a systemic outage, so it's queued normally
 * rather than forced synchronous.
 *
 * QA-audit fix (Finding 5): this docblock already said "queued normally"
 * before this class actually implemented ShouldQueue — without it, every
 * "queued" send here was always synchronous, inline in whatever process
 * called SendReviewRequest::failed() (the queue worker itself). Adjacent
 * to, and surfaced while fixing, that same method's own failing()->
 * failed() rename below it in SendReviewRequest.php — this notification
 * is the other half of the exact "fail loud" pipeline that rename was
 * needed to make run at all.
 */
class ReviewRequestSendFailed extends Notification implements ShouldQueue
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
