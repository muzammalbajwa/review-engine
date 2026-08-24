<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, from AuthController::verifyEmail() — on the *verification*
 * transition, not at registration (the decision doc: "verification is
 * the more meaningful 'real account' moment"). Renders through
 * config('mail.markdown.theme') = 'reviewengine' the same as every other
 * MailMessage-based notification in this app — no per-notification
 * styling needed, that's the whole point of the shared theme
 * (resources/views/vendor/mail/html/themes/reviewengine.css).
 *
 * frontend/DESIGN.md's Voice section: "Plain verbs, second person, no
 * SaaS jargon"; "Say what will happen, not that it has been abstractly
 * scheduled." Brief — one confirmation line, one line of what happens
 * next, one action — not a marketing email.
 */
class WelcomeEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tenantName,
        private readonly bool $onboardingCompleted,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');

        $mail = (new MailMessage)
            ->subject("You're verified — welcome to ReviewEngine")
            ->line("Your email is verified — {$this->tenantName} is ready to start collecting reviews.");

        if ($this->onboardingCompleted) {
            return $mail
                ->line('Review requests already go out automatically as you add customers.')
                ->action('Go to dashboard', "{$frontendUrl}/dashboard");
        }

        return $mail
            ->line('A few steps are still left — connect Google and import your customers, then requests start going out on their own.')
            ->action('Finish setup', "{$frontendUrl}/onboarding");
    }
}
