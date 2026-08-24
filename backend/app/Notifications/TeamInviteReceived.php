<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the invited email address itself (via Notification::route('mail', ...),
 * same pattern as VerifySenderIdentity) — they have no account yet, so
 * there's no notifiable User to attach this to.
 */
class TeamInviteReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tenantName,
        private readonly string $inviterName,
        private readonly string $acceptUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviterName} invited you to join {$this->tenantName} on ReviewEngine")
            ->line("{$this->inviterName} has invited you to join {$this->tenantName}'s ReviewEngine team.")
            ->action('Accept invitation', $this->acceptUrl)
            ->line('If you weren\'t expecting this, you can safely ignore this email.');
    }
}
