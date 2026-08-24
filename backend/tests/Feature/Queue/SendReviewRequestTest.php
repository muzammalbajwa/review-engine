<?php

use App\Jobs\SendReviewRequest;
use App\Mail\ReviewRequestMail;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use App\Models\TimingRule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
    Bus::assertDispatched(SendReviewRequest::class, fn (SendReviewRequest $job) => $job->contactId === $contactId && $job->step === 1);
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
