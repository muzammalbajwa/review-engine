<?php

namespace App\Jobs;

use App\Mail\ReviewRequestMail;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\GbpConnection;
use App\Models\Message;
use App\Models\SenderIdentity;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\TimingRule;
use App\Notifications\ReviewRequestSendFailed;
use App\Services\Queue\BusinessHoursGate;
use App\Services\Templates\TemplateProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * .claude/QUEUE.md's three mechanics, one job class:
 *  1. Step 1 ("check-in"): dispatched by ReleasePendingContacts.
 *  2. Steps 2/3 (delayed follow-up): this job dispatches the NEXT step on
 *     itself with ->delay() after a successful send — "dispatch(new
 *     SendReviewRequest($contact))->delay(...)" is QUEUE.md's own literal
 *     snippet for msg2 -> msg3, reused for msg1 -> msg2 too rather than
 *     writing three near-identical job classes.
 *  3. The clicked_at/reviewed_at check ("the check lives in the job, not
 *     the schedule") happens on every step, not just 2/3 — a click
 *     recorded between release and step 1 actually running should still
 *     suppress the send.
 *
 * Takes $contactId (not a Contact instance) so the queue payload never
 * carries stale model state — same choice SyncReviewsForConnection makes
 * with $gbpConnectionId.
 */
class SendReviewRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * Prerequisite gaps (no verified sender yet, GBP not connected yet,
     * templates page never visited) are almost always temporary —
     * retrying later costs nothing and means nothing silently vanishes
     * once the tenant finishes setup. Only a truly resolved contact
     * (clicked/reviewed) or a duplicate send is a real no-op.
     */
    private const RETRY_SKIP_DELAY_MINUTES = 60;

    /**
     * QA-audit fix (Finding 5): a self-requeue via $skipRetryCount below
     * used to have no ceiling at all — a contact stuck on
     * 'contact_has_no_email' (structurally permanent: nothing about this
     * job, or the passage of time alone, ever gives a phone-only contact
     * an email address — SMS is Phase 4, not built) would re-dispatch
     * itself once an hour, forever, silently, consuming one queue slot
     * per contact for the life of the tenant. 168 retries at the 60-minute
     * interval above is exactly one week — generous enough that a tenant
     * mid-onboarding (still connecting GBP, verifying a sender, writing a
     * compliant template) is never cut off mid-setup, but finite, so a
     * contact that can genuinely never resolve eventually says so instead
     * of running forever. Once exceeded, $this->fail() below — never a
     * silent drop — matching QUEUE.md's "failed jobs go to failed_jobs
     * table + alert" standard the same way a real thrown exception would.
     */
    public const MAX_SKIP_RETRIES = 168;

    public function __construct(
        public readonly int $contactId,
        public readonly int $step = 1,
        public readonly int $skipRetryCount = 0,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("contact:{$this->contactId}:step:{$this->step}"))->expireAfter(600)];
    }

    public function handle(): void
    {
        // Same two-phase pattern as SyncReviewsForConnection: no tenant
        // context exists yet in a queue worker, so the very first lookup
        // has to bypass RLS deliberately (scoped to exactly this one
        // by-id lookup — $this->contactId came from the trusted
        // dispatcher, never request input) before the real tenant context
        // can even be known.
        $tenantId = DB::transaction(function () {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            return Contact::withoutGlobalScopes()->find($this->contactId)?->tenant_id;
        });

        if ($tenantId === null) {
            // Deleted since this job was queued — nothing to do.
            return;
        }

        $plan = $this->withTenantContext($tenantId, fn () => $this->prepare($tenantId));

        if ($plan['action'] === 'redelay') {
            self::dispatch($this->contactId, $this->step)->delay(now()->addMinutes($plan['delay_minutes']));

            return;
        }

        if ($plan['action'] === 'skip') {
            Log::info('SendReviewRequest skipped', [
                'contact_id' => $this->contactId,
                'step' => $this->step,
                'reason' => $plan['reason'],
                'skip_retry_count' => $this->skipRetryCount,
            ]);

            if ($plan['retry']) {
                if ($this->skipRetryCount >= self::MAX_SKIP_RETRIES) {
                    $this->fail(
                        "SendReviewRequest gave up on contact {$this->contactId} step {$this->step} after ".
                        self::MAX_SKIP_RETRIES." retries over roughly a week — still blocked on: {$plan['reason']}"
                    );

                    return;
                }

                self::dispatch($this->contactId, $this->step, $this->skipRetryCount + 1)
                    ->delay(now()->addMinutes(self::RETRY_SKIP_DELAY_MINUTES));
            }

            return;
        }

        // The actual send happens outside any DB transaction — an HTTP/SMTP
        // call has no business holding a Postgres transaction open, and if
        // something else in that transaction ever rolled back later, we'd
        // have sent mail the DB never recorded.
        $sentMessage = Mail::to($plan['to'])->send(new ReviewRequestMail(
            renderedBody: $plan['rendered_body'],
            fromEmail: $plan['from_email'],
            fromName: $plan['from_name'],
        ));

        $this->withTenantContext($tenantId, function () use ($plan, $sentMessage) {
            $message = Message::find($plan['message_id']);
            $message->status = 'sent';
            $message->sent_at = now();
            $message->provider_id = $sentMessage?->getMessageId();
            $message->save();

            if ($this->step === 1) {
                $contact = Contact::find($this->contactId);
                $contact->status = 'sent';
                $contact->save();
            }
        });

        if ($this->step < 3) {
            $nextStep = $this->step + 1;
            $delayMinutes = $nextStep === 2 ? $plan['delay_minutes_step2'] : $plan['delay_minutes_step3'];

            self::dispatch($this->contactId, $nextStep)->delay(now()->addMinutes($delayMinutes));
        }
    }

    /**
     * A queue job has no 'tenant' middleware to activate context the way
     * an HTTP request does — SetTenantContext's own docblock spells out
     * both halves that middleware normally sets together: the Postgres
     * session variable (Layer B, RLS) AND CurrentTenant, the in-memory
     * app singleton (Layer A, TenantScope + BelongsToTenant's tenant_id
     * auto-fill on create). Setting only the DB session var — which is
     * all the RLS-bypass pattern elsewhere in this job needs — silently
     * left tenant_id unset on any model created here that relies on
     * auto-fill instead of an explicit assignment (caught live: a NOT
     * NULL violation on templates.tenant_id via TemplateProvisioner,
     * which was written assuming an HTTP request always sets this).
     * Cleared in `finally` for the same reason the middleware does —
     * a persistent queue worker must never leak tenant context into
     * whatever job runs next.
     */
    private function withTenantContext(string $tenantId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($tenantId, $callback) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
            app(CurrentTenant::class)->set($tenantId);

            try {
                return $callback();
            } finally {
                app(CurrentTenant::class)->clear();
            }
        });
    }

    /**
     * Runs with real tenant context already active. Returns a plain array
     * (never a model) so the caller can send mail *after* this transaction
     * has closed.
     */
    private function prepare(string $tenantId): array
    {
        $contact = Contact::find($this->contactId);

        if ($contact === null) {
            return ['action' => 'skip', 'reason' => 'contact_not_found', 'retry' => false];
        }

        // .claude/QUEUE.md: "The check lives in the job, not the
        // schedule." Any prior step's click/review suppresses every
        // later step — checked fresh on every step, not just 2/3, so a
        // click landing between release and step 1 actually running
        // still suppresses it.
        $alreadyResolved = Message::query()
            ->where('contact_id', $contact->id)
            ->where(fn ($q) => $q->whereNotNull('clicked_at')->orWhereNotNull('reviewed_at'))
            ->exists();

        if ($alreadyResolved) {
            return ['action' => 'skip', 'reason' => 'contact_already_clicked_or_reviewed', 'retry' => false];
        }

        $timingRule = TimingRule::findOrCreateDefault();
        $businessHours = app(BusinessHoursGate::class);

        if (! $businessHours->isOpen($timingRule)) {
            return ['action' => 'redelay', 'delay_minutes' => $businessHours->minutesUntilNextOpen($timingRule)];
        }

        if ($contact->email === null) {
            // Sender identities and Mail are email-only right now (no
            // `type` column on sender_identities, no SMS provider wired
            // up) — .claude/ROADMAP.md: SMS is Phase 4. A phone-only
            // contact has no channel to send through yet.
            return ['action' => 'skip', 'reason' => 'contact_has_no_email', 'retry' => true];
        }

        $campaign = Campaign::findOrCreateDefault();
        app(TemplateProvisioner::class)->ensureDefaults($campaign);

        $template = Template::query()
            ->where('campaign_id', $campaign->id)
            ->where('step', $this->step)
            ->where('compliance_status', 'pass')
            ->first();

        if ($template === null) {
            return ['action' => 'skip', 'reason' => 'no_compliance_passed_template', 'retry' => true];
        }

        $sender = SenderIdentity::query()->where('verified', true)->first();

        if ($sender === null) {
            return ['action' => 'skip', 'reason' => 'no_verified_sender_identity', 'retry' => true];
        }

        // .claude/COMPLIANCE.md rule #1 / .claude/CLAUDE.md golden rule #3:
        // every contact gets the SAME review link — this is the one review
        // link for the tenant's connected GBP, read once here, never
        // branched on anything about the contact.
        $connection = GbpConnection::query()
            ->where('status', 'connected')
            ->whereNotNull('review_link')
            ->first();

        if ($connection === null) {
            return ['action' => 'skip', 'reason' => 'no_connected_gbp_review_link', 'retry' => true];
        }

        // Idempotency backstop: messages.UNIQUE(tenant_id, contact_id, step)
        // makes this safe even under a genuine race between two workers —
        // whichever loses firstOrCreate() gets the already-existing row back.
        $message = Message::query()->firstOrCreate(
            ['contact_id' => $contact->id, 'step' => $this->step],
            ['status' => 'pending']
        );

        if ($message->status === 'sent') {
            return ['action' => 'skip', 'reason' => 'step_already_sent', 'retry' => false];
        }

        // Generated here rather than inside the firstOrCreate() call above:
        // firstOrCreate()'s create-attributes only apply on first creation,
        // so a 'pending' message row left over from before click tracking
        // existed would otherwise carry click_token = null forever — this
        // covers both that case and the normal first-send case in one
        // place. Str::random(48), same generator/length as
        // tenants.quick_add_token (~285 bits) — unguessable by
        // construction, not by being kept secret from RLS.
        if ($message->click_token === null) {
            $message->click_token = Str::random(48);
            $message->save();
        }

        $tenant = Tenant::find($tenantId);

        // The link every contact gets points at our own tracked redirect,
        // never the raw Google URL directly — GET /api/v1/click/{token}
        // records clicked_at (once) and forwards to $connection->review_link
        // itself. Same link, same destination, for every contact
        // regardless of anything about them (.claude/COMPLIANCE.md rule
        // #1) — the token identifies *who* clicked for suppression
        // purposes, it never routes anyone to a different destination.
        $clickUrl = route('click.redirect', ['token' => $message->click_token]);

        $renderedBody = strtr($template->body, [
            '{name}' => $contact->name,
            '{business_name}' => $tenant->name,
            '{review_link}' => $clickUrl,
        ]);

        return [
            'action' => 'send',
            'message_id' => $message->id,
            'to' => $contact->email,
            'rendered_body' => $renderedBody,
            'from_email' => $sender->from_email,
            'from_name' => $sender->from_name,
            'delay_minutes_step2' => $timingRule->delay_minutes_step2,
            'delay_minutes_step3' => $timingRule->delay_minutes_step3,
        ];
    }

    /**
     * .claude/QUEUE.md: "Failed jobs go to failed_jobs table + alert."
     * Laravel writes the failed_jobs row automatically once $tries is
     * exhausted; this only owns the "+ alert" half, throttled per-tenant
     * so an outage affecting many contacts at once (e.g. Mailpit/Resend
     * down) sends one email, not one per contact.
     *
     * QA-audit fix (Finding 5): this was named failing() before — a
     * plausible-looking but nonexistent Laravel hook. The real one
     * Illuminate\Queue\CallQueuedHandler actually calls (verified
     * directly: it does `method_exists($command, 'failed')`) is
     * failed(Throwable $e), no -ing. Renaming here is what makes this
     * method run at all — for a genuine exhausted-retries failure AND for
     * the new MAX_SKIP_RETRIES give-up path above, both of which call
     * $this->fail(), which in turn calls this. Confirmed live: the ops
     * alert email never fired before this rename, for either failure
     * path, with nothing anywhere to say why — the exact "confident
     * success, no real work happening" failure class .claude/QUEUE.md's
     * "failed jobs go to failed_jobs table + alert" line exists to rule
     * out.
     */
    public function failed(Throwable $e): void
    {
        $tenantId = DB::transaction(function () {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            return Contact::withoutGlobalScopes()->find($this->contactId)?->tenant_id;
        });

        if ($tenantId === null) {
            return;
        }

        Log::error('SendReviewRequest failed permanently', [
            'contact_id' => $this->contactId,
            'step' => $this->step,
            'tenant_id' => $tenantId,
            'error' => $e->getMessage(),
        ]);

        $throttleKey = "review_request_send_failed_alert:{$tenantId}";

        if (Cache::has($throttleKey)) {
            return;
        }

        Cache::put($throttleKey, true, now()->addMinutes(15));

        $email = config('services.ops.alert_email');

        if (empty($email)) {
            return;
        }

        Notification::route('mail', $email)->notify(
            new ReviewRequestSendFailed($tenantId, $this->contactId, $this->step, $e->getMessage())
        );
    }
}
