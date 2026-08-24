<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's own built-in email-verification notification
 * (Illuminate\Auth\Notifications\VerifyEmail) generates the real signed
 * URL — the `verification.verify` route, `id`+`hash`+expiry+signature —
 * nothing about that mechanism is reinvented here (the "add email
 * verification" decision doc: "use it rather than inventing a custom
 * token scheme"). The only change from the stock class: ShouldQueue.
 * Laravel's own VerifyEmail sends synchronously by default, which this
 * app never does for a real outbound email — every other notification
 * here (TeamInviteReceived, VerifySenderIdentity, GbpConnectionRevoked,
 * SubscriptionRenewalReminder) already implements ShouldQueue.
 */
class VerifyEmailAddress extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
