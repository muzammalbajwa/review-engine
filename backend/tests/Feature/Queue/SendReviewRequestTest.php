<?php

use App\Jobs\SendReviewRequest;
use App\Mail\ReviewRequestMail;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use App\Models\TimingRule;
use App\Notifications\ReviewRequestSendFailed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * .claude/QUEUE.md's three mechanics + .claude/CLAUDE.md golden rule #3
 * (no gating — the same review link for everyone). No job/command for
 * any of this existed before this test file.
 */
function makeQuickAddContact(string $sessionToken, string $name, string $email): int
{
    $response = test()->withHeader('Authorization', "Bearer {$sessionToken}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => $name, 'email' => $email])
        ->assertCreated();

    return $response->json('data.id');
}

function makeTimingRule(string $tenantId, string $start, string $end): void
{
    DB::transaction(function () use ($tenantId, $start, $end) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        // Not firstOrCreate([], [...]) — its create() path is mass
        // assignment, and tenant_id is deliberately excluded from
        // TimingRule::$fillable (same reasoning as every other tenant
        // model). That silently drops tenant_id from the insert and trips
        // RLS's WITH CHECK, exactly like SyncReviewsForConnection's own
        // docblock already warns about for Review.
        $rule = new TimingRule([
            'delay_minutes_step2' => 4320,
            'delay_minutes_step3' => 10080,
            'business_hours_start' => $start,
            'business_hours_end' => $end,
            'timezone' => 'UTC',
        ]);
        $rule->tenant_id = $tenantId;
        $rule->save();
    });
}

function setTenantBusinessHoursWideOpen(string $tenantId): void
{
    makeTimingRule($tenantId, '00:00:00', '23:59:59');
}

function setTenantBusinessHoursClosed(string $tenantId): void
{
    makeTimingRule($tenantId, '09:00:00', '09:01:00');
}

test('sending step 1 to a fully set-up contact sends real mail, records the message, and flips the contact to sent', function () {
    [$token, $tenantId] = seedCustomerAccount('Send Step1');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Priya Shah', 'priya@example.com');

    Mail::fake();
    Bus::fake([SendReviewRequest::class]); // fake only the *next* dispatch (step 2), not this one

    (new SendReviewRequest($contactId, 1))->handle();

    // The body carries our own tracked click URL, never the raw Google
    // link directly (Phase 2 Step 4, see SendReviewRequest::prepare()'s
    // own docblock) — asserted precisely below via the actual persisted
    // click_token, not just "some click.redirect URL is present".
    Mail::assertSent(ReviewRequestMail::class, function (ReviewRequestMail $mail) {
        return str_contains($mail->renderedBody, 'Priya Shah')
            && str_contains($mail->renderedBody, 'Send Step1 Co')
            && ! str_contains($mail->renderedBody, 'https://g.page/r/test-location/review');
    });

    $message = DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Message::where('contact_id', $contactId)->where('step', 1)->first();
    });
    expect($message->status)->toBe('sent');
    expect($message->sent_at)->not->toBeNull();
    expect($message->click_token)->not->toBeNull();
    expect($message->click_token)->toMatch('/^[A-Za-z0-9]{48}$/');

    Mail::assertSent(ReviewRequestMail::class, function (ReviewRequestMail $mail) use ($message) {
        return str_contains($mail->renderedBody, route('click.redirect', ['token' => $message->click_token]));
    });

    $contact = DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Contact::find($contactId);
    });
    expect($contact->status)->toBe('sent');

    Bus::assertDispatched(SendReviewRequest::class, function (SendReviewRequest $job) use ($contactId) {
        return $job->contactId === $contactId && $job->step === 2;
    });
});

test('a contact with no email is skipped, not crashed on, and retried later', function () {
    [$token, $tenantId] = seedCustomerAccount('No Email Channel');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Phone Only', 'phone' => '+15555550100'])
        ->assertCreated();
    $contactId = $response->json('data.id');

    Mail::fake();
    Bus::fake([SendReviewRequest::class]);

    (new SendReviewRequest($contactId, 1))->handle();

    Mail::assertNothingSent();
    // QA-audit fix (Finding 5): the redispatch carries skipRetryCount + 1
    // (1, from a fresh 0) — this is the counter MAX_SKIP_RETRIES below
    // is checked against, proving a first-time skip doesn't already
    // start pre-loaded near the cap.
    Bus::assertDispatched(SendReviewRequest::class, fn (SendReviewRequest $job) => $job->contactId === $contactId && $job->step === 1 && $job->skipRetryCount === 1);
});

test('QA-audit fix (Finding 5): a skip-and-retry one step below the cap still redispatches normally', function () {
    [$token, $tenantId] = seedCustomerAccount('One Below Cap');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Almost At Cap', 'phone' => '+15555550100'])
        ->assertCreated();
    $contactId = $response->json('data.id');

    Mail::fake();
    Bus::fake([SendReviewRequest::class]);

    (new SendReviewRequest($contactId, 1, SendReviewRequest::MAX_SKIP_RETRIES - 1))->handle();

    Bus::assertDispatched(
        SendReviewRequest::class,
        fn (SendReviewRequest $job) => $job->contactId === $contactId
            && $job->step === 1
            && $job->skipRetryCount === SendReviewRequest::MAX_SKIP_RETRIES
    );
});

test('QA-audit fix (Finding 5): a skip-and-retry AT the cap gives up instead of requeuing forever', function () {
    [$token, $tenantId] = seedCustomerAccount('At The Cap');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Never Resolves', 'phone' => '+15555550100'])
        ->assertCreated();
    $contactId = $response->json('data.id');

    Mail::fake();
    Bus::fake([SendReviewRequest::class]);

    (new SendReviewRequest($contactId, 1, SendReviewRequest::MAX_SKIP_RETRIES))->handle();

    // The whole point: no self-requeue once the cap is reached — this is
    // what used to run forever, once an hour, indefinitely.
    Bus::assertNotDispatched(SendReviewRequest::class);
});

test('QA-audit fix (Finding 5), real evidence: giving up at the cap lands a real row in failed_jobs and fires the real ops alert email — through a genuine queue round-trip, not a direct handle() call', function () {
    config(['queue.default' => 'redis']);
    config(['services.ops.alert_email' => 'ops@example.com']);

    [$token, $tenantId] = seedCustomerAccount('Real Give Up');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Genuinely Stuck', 'phone' => '+15555550100'])
        ->assertCreated();
    $contactId = $response->json('data.id');

    Notification::fake();

    $failedBefore = DB::table('failed_jobs')->count();

    // A real dispatch onto the real redis 'default' queue, at exactly the
    // give-up boundary, then processed by the REAL `php artisan
    // queue:work` command — not a direct ->handle() call, and not a
    // hand-rolled pop()+fire() either. Both matter here specifically:
    // (1) InteractsWithQueue::fail() is a no-op unless $this->job is set,
    // which only happens when a job genuinely runs through the queue
    // system, so a direct ->handle() call (as the two boundary tests
    // above deliberately use, to test the condition fast) would silently
    // skip the entire failed_jobs + alert path. (2) Writing the
    // failed_jobs row is NOT something Job::fail()/the JobFailed event
    // does by itself — confirmed directly in
    // Illuminate\Queue\Console\WorkCommand::listenForEvents(), the actual
    // JobFailed -> failed_jobs listener is registered by that Artisan
    // command itself, nowhere else — so even a real pop()+fire() bypasses
    // persistence entirely unless the real command runs. --once makes
    // this terminate after exactly one job instead of daemonizing.
    SendReviewRequest::dispatch($contactId, 1, SendReviewRequest::MAX_SKIP_RETRIES);
    test()->artisan('queue:work', [
        'connection' => 'redis',
        '--queue' => 'default',
        '--once' => true,
    ]);

    expect(DB::table('failed_jobs')->count())->toBe($failedBefore + 1);

    $failedRow = DB::table('failed_jobs')->orderByDesc('id')->first();
    expect($failedRow->exception)->toContain((string) SendReviewRequest::MAX_SKIP_RETRIES);
    expect($failedRow->exception)->toContain('contact_has_no_email');

    // QA-audit fix (Finding 5): this also proves the failing() -> failed()
    // rename in SendReviewRequest.php actually matters — Laravel's real
    // hook is failed(Throwable $e), never failing(); before that rename,
    // $this->fail() above would still write the failed_jobs row (that
    // part is pure framework behavior, name notwithstanding) but this
    // notification would never have been dispatched at all, silently.
    Notification::assertSentOnDemand(
        ReviewRequestSendFailed::class,
        function ($notification, $channels, $notifiable) use ($contactId) {
            return $notifiable->routes['mail'] === 'ops@example.com'
                && $notification->contactId === $contactId;
        }
    );
});

test('missing a verified sender identity skips the send and retries later, without crashing', function () {
    [$token, $tenantId] = seedCustomerAccount('No Sender Yet');
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Waiting On Sender', 'wait@example.com');

    Mail::fake();
    Bus::fake([SendReviewRequest::class]);

    (new SendReviewRequest($contactId, 1))->handle();

    Mail::assertNothingSent();
});

test('missing a connected GBP review link skips the send, never inventing or omitting the review link', function () {
    [$token, $tenantId] = seedCustomerAccount('No GBP Yet');
    makeVerifiedSenderIdentity($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Waiting On GBP', 'waitgbp@example.com');

    Mail::fake();

    (new SendReviewRequest($contactId, 1))->handle();

    Mail::assertNothingSent();
});

test('outside business hours, the job re-delays itself instead of sending or dropping the contact', function () {
    [$token, $tenantId] = seedCustomerAccount('Closed Hours');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursClosed($tenantId);
    $contactId = makeQuickAddContact($token, 'After Hours', 'afterhours@example.com');

    Mail::fake();
    Bus::fake([SendReviewRequest::class]);

    (new SendReviewRequest($contactId, 1))->handle();

    Mail::assertNothingSent();
    Bus::assertDispatched(SendReviewRequest::class, fn (SendReviewRequest $job) => $job->contactId === $contactId && $job->step === 1);
});

test('a contact who already clicked is never sent a later step — the no-op lives in the job', function () {
    [$token, $tenantId] = seedCustomerAccount('Already Clicked');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Clicked Already', 'clicked@example.com');

    DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        // Not Message::create([...]) — mass assignment, and tenant_id is
        // deliberately excluded from Message::$fillable. Explicit
        // assignment instead, same as every other tenant-model helper in
        // this file.
        $message = new Message([
            'contact_id' => $contactId,
            'step' => 1,
            'status' => 'sent',
            'sent_at' => now()->subDays(3),
            'click_token' => 'audit-click-token-1',
            'clicked_at' => now()->subDays(2),
        ]);
        $message->tenant_id = $tenantId;
        $message->save();
    });

    Mail::fake();
    Bus::fake([SendReviewRequest::class]);

    (new SendReviewRequest($contactId, 2))->handle();

    Mail::assertNothingSent();
    Bus::assertNotDispatched(SendReviewRequest::class);
});

test('dispatching the same contact and step twice never creates two message rows or sends twice', function () {
    [$token, $tenantId] = seedCustomerAccount('Idempotent Send');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Twice Dispatched', 'twice@example.com');

    Mail::fake();
    // QUEUE_CONNECTION=sync in testing (phpunit.xml) — without this, the
    // job's own self-chained dispatch to step 2 (then 3) would run inline
    // within the very first handle() call and inflate the mail count
    // through real step 2/3 sends, unrelated to what this test is
    // actually checking: that step *1* specifically is never double-sent.
    Bus::fake([SendReviewRequest::class]);

    (new SendReviewRequest($contactId, 1))->handle();
    (new SendReviewRequest($contactId, 1))->handle();

    Mail::assertSentCount(1);

    $count = DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Message::where('contact_id', $contactId)->where('step', 1)->count();
    });
    expect($count)->toBe(1);
});

test('a template with compliance_status=block is never used to send, even with sender/GBP/hours all otherwise ready', function () {
    [$token, $tenantId] = seedCustomerAccount('Blocked Template Gate');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Should Not Receive This', 'blocked-template@example.com');

    // TemplateController's own save() path can never persist a blocked
    // body (.claude/CLAUDE.md golden rule #4) — this simulates the only
    // way a non-'pass' row could exist (a hypothetical corrupted state,
    // or a future direct-write bug elsewhere), to prove the send job
    // itself is the one actually enforcing the gate, not just trusting
    // that nothing blocked ever reaches the templates table.
    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $campaign = Campaign::findOrCreateDefault();
        $template = new Template([
            'campaign_id' => $campaign->id,
            'step' => 1,
            'body' => 'Give us 5 stars or mention Dave by name!',
            'compliance_status' => 'block',
            'compliance_reasons' => ['star rating request', 'staff name request'],
        ]);
        $template->tenant_id = $tenantId;
        $template->save();
    });

    Mail::fake();

    (new SendReviewRequest($contactId, 1))->handle();

    Mail::assertNothingSent();

    $contact = DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Contact::find($contactId);
    });
    expect($contact->status)->toBe('pending'); // never advanced past pending
});
