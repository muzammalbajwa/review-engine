<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

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
 *
 * QA-audit fix (Finding 1, CRITICAL): this notification is dispatched via
 * Notification::route('mail', $email)->notify(...) — see
 * User::sendEmailVerificationNotification() — never via
 * $user->notify(...) any more. Dispatching against a real Eloquent User
 * meant Laravel's queued-notification handling
 * (Illuminate\Notifications\NotificationSender::formatNotifiables()) had
 * wrapped that single User into an EloquentCollection before queueing
 * (Illuminate\Notifications\SendQueuedNotifications::$notifiables is
 * always a Collection, even for one notifiable) — so on the WORKER,
 * unserializing that job re-fetches the collection by id via
 * Illuminate\Queue\SerializesAndRestoresModelIdentifiers::
 * restoreCollection(), which queries with plain ->get(), not
 * ->firstOrFail(). This app's Row-Level Security requires
 * app.current_tenant_id/app.is_admin to be set on the DB session before
 * any tenant table (including users) can be read — a real, separate
 * queue worker process has neither set at that point, so that query
 * legitimately returns zero rows. restoreCollection() doesn't throw on
 * that: it just filters the requested ids down to whatever it actually
 * found, silently producing an EMPTY collection. $notifiables ends up
 * empty, the job's own foreach loop over it runs zero times, and the job
 * completes as an ordinary, unremarkable success — no exception
 * anywhere, no failed_jobs row, no log line (confirmed directly: firing
 * the exact same job shape by hand showed hasFailed()=false,
 * isDeleted()=true, failed_jobs unchanged). The verification email was
 * simply never sent, and nothing about that request/job pair looks any
 * different from one that worked. (Confirmed live, and confirmed
 * invisible to `php artisan test`, which runs QUEUE_CONNECTION=sync —
 * "queued" jobs run inline in the same request/process/DB session that
 * already has tenant context, so this restoration always succeeds under
 * test and the bug never triggers there.)
 *
 * $notifiable is an AnonymousNotifiable once dispatched this way, with
 * neither getKey() nor getEmailForVerification() — the base class's own
 * verificationUrl() calls both, so it's overridden below to use the two
 * plain scalars this notification actually needs instead. This is the
 * same "pass primitives, not a model" shape TeamInviteReceived (and,
 * along the mail-building side, WelcomeEmail/GbpConnectionRevoked/
 * SubscriptionRenewalReminder) already use — TeamInviteReceived already
 * proved this pattern delivers correctly through a real out-of-process
 * worker.
 */
class VerifyEmailAddress extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int|string $userId,
        private readonly string $emailForVerification,
    ) {
        // QA-audit fix (Finding 5): a dedicated, higher-priority queue —
        // never the 'default' one App\Jobs\SendReviewRequest's bulk drip
        // retries also live on. Workers run --queue=transactional,default
        // (config/horizon.php, composer.json's dev script) specifically
        // so a backlog of low-value retries can never delay this. See
        // .claude/QUEUE.md's "Queue priority" section for the full
        // rationale — every notification in this "transactional" bucket
        // (VerifyEmailAddress, WelcomeEmail, GbpConnectionRevoked,
        // SubscriptionRenewalReminder, TeamInviteReceived,
        // VerifySenderIdentity) sets this same queue name in its own
        // constructor, deliberately not centralized into one shared trait
        // — each of these already has (or needed) its own constructor for
        // unrelated reasons, and six one-line calls are less machinery
        // than a trait whose only job is running inside a constructor
        // some of these classes don't otherwise have.
        $this->onQueue('transactional');
    }

    /**
     * Overridden so this never touches $notifiable at all — see this
     * class's own docblock above for why $notifiable can no longer be
     * relied on to be a real, rehydratable User.
     */
    protected function verificationUrl($notifiable)
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $this->userId,
                'hash' => sha1($this->emailForVerification),
            ]
        );
    }
}
