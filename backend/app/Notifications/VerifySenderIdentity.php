<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * .claude/CLAUDE.md Phase 2 Step 2: "a simple verification flow... so a
 * tenant's messages don't get sent from an unverified address." Sent to
 * the claimed from_email itself (via Notification::route('mail', ...)),
 * never to the tenant's account owner — the whole point is proving
 * control of *this* mailbox before the send job is allowed to use it as a
 * From address.
 */
class VerifySenderIdentity extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $verifyUrl)
    {
        // QA-audit fix (Finding 5): dedicated 'transactional' queue,
        // drained before 'default' — see VerifyEmailAddress's own
        // constructor for the full rationale, shared by every
        // notification in this bucket.
        $this->onQueue('transactional');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify this email address for ReviewEngine')
            ->line('Confirm that you control this email address to start sending review request messages from it.')
            ->action('Verify email address', $this->verifyUrl)
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
