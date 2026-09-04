<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by ContactMessageController::store() to config('services.support.inbox')
 * via an on-demand Notification::route('mail', ...) call (same shape as
 * WorkerHeartbeatDown/TeamInviteReceived/VerifySenderIdentity — there's no
 * User/Tenant "notifiable" for a prospect who hasn't signed up, the whole
 * point of this form). Renders through the shared 'reviewengine' markdown
 * theme like every other MailMessage notification in this app — no
 * per-notification styling needed.
 *
 * Deliberately NOT queued (no ShouldQueue), same reasoning as
 * WorkerHeartbeatDown's own docblock: the controller has to know
 * synchronously whether the send actually succeeded before it tells the
 * visitor "sent" — this app has already shipped silent-failure bugs
 * (compliance checker, register flow) where a queued/fire-and-forget send
 * let the UI claim success regardless of what really happened downstream.
 * A contact form is exactly the case where that failure mode is worst: a
 * prospect who thinks they reached a human, and didn't.
 */
class ContactMessageReceived extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $fromName,
        private readonly string $fromEmail,
        private readonly string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New contact form message from {$this->fromName}")
            // So support can hit "Reply" in their mail client and land
            // directly on the visitor's address, not ours.
            ->replyTo($this->fromEmail, $this->fromName)
            ->line("From: {$this->fromName} ({$this->fromEmail})")
            ->line('Message:')
            ->line($this->body);
    }
}
