<?php

use App\Models\GbpConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\GbpConnectionRevoked;
use App\Notifications\SubscriptionRenewalReminder;
use App\Notifications\VerifyEmailAddress;
use App\Notifications\WelcomeEmail;
use App\Services\Gbp\GbpConnectionRevokedException;
use App\Services\Gbp\GbpTokenRefresher;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * QA-audit Finding 1 (CRITICAL) permanent regression guard.
 *
 * The bug this file exists to catch: VerifyEmailAddress, WelcomeEmail,
 * GbpConnectionRevoked, and SubscriptionRenewalReminder were all dispatched
 * via $eloquentUser->notify(...) — a real Eloquent User as the notifiable.
 * Illuminate\Notifications\NotificationSender::formatNotifiables() always
 * wraps a single notifiable into an EloquentCollection before queueing, so
 * on a real, separate worker process, unserializing the job re-fetches
 * that collection by id via Illuminate\Queue\
 * SerializesAndRestoresModelIdentifiers::restoreCollection() — which
 * queries with plain ->get(), not ->firstOrFail(). This app's Row-Level
 * Security requires app.current_tenant_id/app.is_admin to be set on the DB
 * session before any tenant table (including users) can be read; a real
 * worker process has neither set. restoreCollection() doesn't throw when
 * that query comes back empty — it just filters the requested ids down to
 * whatever it found, silently producing an EMPTY collection. The job's own
 * foreach over that empty collection runs zero times and the job completes
 * as an ordinary, unremarkable success: no exception, no failed_jobs row,
 * no log line. The email was simply never sent.
 *
 * Every notification here is now dispatched via
 * Notification::route('mail', $email)->notify(...) instead — see each
 * notification's own docblock / call site — which has no Eloquent model to
 * rehydrate at all.
 *
 * WHY THIS NEEDS ITS OWN TEST, SEPARATE FROM EVERY OTHER TEST IN THIS
 * SUITE THAT ALREADY ASSERTS THESE NOTIFICATIONS GET SENT: .env.testing
 * sets QUEUE_CONNECTION=sync, under which a "queued" job runs inline, in
 * the exact same request/process/DB session that dispatched it — the
 * tenant context SetTenantContext middleware already set is still active,
 * so the restoration above always succeeds and this entire bug class is
 * invisible no matter how many sync-queue tests pass. This file
 * deliberately overrides queue.default to the real redis connection (the
 * same one config/queue.php and every real deployment of this app uses —
 * see .env's own REDIS_* block, unchanged here) for exactly the tests
 * below, then manually pops the resulting job and — before firing it —
 * clears every trace of tenant context (the RLS session var AND the
 * CurrentTenant PHP singleton), reproducing the blank slate a genuinely
 * separate `queue:work`/Horizon worker process actually starts with. If
 * any of these four notifications is ever dispatched against a real
 * Eloquent model again, this is what will fail — silently, everywhere
 * else, exactly like the original bug — while every sync-queue assertion
 * elsewhere in this suite keeps passing.
 *
 * mail.default is overridden to the 'array' transport (config/mail.php) —
 * a real Mailer/MailChannel/Markdown build with a real Symfony Email
 * object at the end of it, just collected in memory instead of opening a
 * real SMTP connection, so this runs in plain CI with no mail catcher
 * service required. This is NOT Mail::fake(): MailFake::send() only
 * records \Illuminate\Contracts\Mail\Mailable instances, and
 * MailChannel's own mail-message path never builds one (it calls the raw
 * Mailer::send($view, $data, $callback) signature directly) — Mail::fake()
 * would silently record nothing here and this test would pass for the
 * wrong reason. The QA audit's own manual verification of this fix used a
 * real local Mailpit instance; this automated version proves the identical
 * mechanism without needing one.
 */
function fireOneRealRedisJobWithNoTenantContext(): void
{
    // QA-audit fix (Finding 5): all four notifications this file tests
    // (VerifyEmailAddress, WelcomeEmail, GbpConnectionRevoked,
    // SubscriptionRenewalReminder) now declare ->onQueue('transactional')
    // in their own constructors — see .claude/QUEUE.md's "Queue
    // priority" section — so this pops from there, never 'default',
    // which is App\Jobs\SendReviewRequest's own queue and no longer
    // where any of these land.
    $job = Queue::connection('redis')->pop('transactional');

    expect($job)->not->toBeNull(
        'Expected a real job to be sitting on the redis "transactional" queue — none was pushed. '.
        'Check the notification is actually being dispatched before this helper runs.'
    );

    // The blank slate a real, separate worker process actually starts
    // with — never inherited from whatever request/process pushed the
    // job. This is the one line that makes this test mean anything: skip
    // it and the restoration below trivially succeeds, same as it always
    // has under the sync driver.
    DB::statement("SELECT set_config('app.current_tenant_id', '', true)");
    DB::statement("SELECT set_config('app.is_admin', '', true)");
    app(CurrentTenant::class)->clear();

    $job->fire();
}

function assertRealMailSentTo(string $toEmail, string $subjectContains): void
{
    $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

    $match = collect($messages)->first(function ($sent) use ($toEmail, $subjectContains) {
        $email = $sent->getOriginalMessage();
        $toAddresses = array_map(fn ($address) => $address->getAddress(), $email->getTo());

        return in_array($toEmail, $toAddresses, true)
            && str_contains((string) $email->getSubject(), $subjectContains);
    });

    expect($match)->not->toBeNull(
        "Expected a real mail message to {$toEmail} with subject containing '{$subjectContains}' — ".
        'found none among '.count($messages).' message(s) actually built and sent through the real '.
        'Mailer/MailChannel pipeline. If this fails after a code change, check whether a notification '.
        'is being dispatched via $eloquentModel->notify(...) again instead of Notification::route(...) — '.
        'see this file\'s own docblock.'
    );
}

beforeEach(function () {
    config(['queue.default' => 'redis']);
    config(['mail.default' => 'array']);
    app('mail.manager')->mailer('array')->getSymfonyTransport()->flush();
});

test('CRITICAL: VerifyEmailAddress survives a real redis queue round-trip with zero tenant context on the worker side', function () {
    [, , $userId] = seedUnverifiedCustomerAccount('Real Queue Verify');

    $email = DB::transaction(function () use ($userId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return User::withoutGlobalScopes()->find($userId)->email;
    });

    fireOneRealRedisJobWithNoTenantContext();

    assertRealMailSentTo($email, 'Verify Email Address');
});

test('CRITICAL: WelcomeEmail survives a real redis queue round-trip with zero tenant context on the worker side', function () {
    [, $tenantId, $userId] = seedUnverifiedCustomerAccount('Real Queue Welcome');

    $email = DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->find($userId)->email;
    });

    // Drain the registration's own VerifyEmailAddress job first — it's
    // first in line on the real queue and isn't what this test is about
    // (the test above already covers it). Restores real tenant context
    // afterward the same way TestCase::call() does between simulated
    // requests elsewhere in this suite (see GbpRevocationAlertTest.php's
    // own comment on this exact pattern).
    fireOneRealRedisJobWithNoTenantContext();
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

    $this->getJson(verifyUrlFor($userId, $email))->assertRedirect();

    fireOneRealRedisJobWithNoTenantContext();

    assertRealMailSentTo($email, "You're verified");
});

test('CRITICAL: GbpConnectionRevoked survives a real redis queue round-trip with zero tenant context on the worker side', function () {
    $tenantId = (string) Str::uuid();
    $email = 'realqueuegbp-'.uniqid().'@example.com';

    $connection = DB::transaction(function () use ($tenantId, $email) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => 'Real Queue GBP Co', 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User(['name' => 'Real Queue GBP Owner', 'email' => $email, 'password' => Hash::make('x')]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->markEmailAsVerified();
        $user->save();

        $connection = new GbpConnection([
            'oauth_token' => 'expired-token',
            'refresh_token' => 'dead-refresh-token',
            'token_expires_at' => now()->subMinute(),
            'location_id' => 'locations/real-queue',
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantId;
        $connection->save();

        return $connection;
    });

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    // Same real-tenant-context wrapping SyncReviewsForConnection::handle()
    // itself uses around this exact call — GbpTokenRefresher::
    // markRevoked()'s internal owner lookup needs it.
    DB::transaction(function () use ($tenantId, $connection) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        expect(fn () => app(GbpTokenRefresher::class)->refreshIfNeeded($connection))
            ->toThrow(GbpConnectionRevokedException::class);
    });

    fireOneRealRedisJobWithNoTenantContext();

    assertRealMailSentTo($email, 'Google Business Profile connection needs attention');
});

test('CRITICAL: SubscriptionRenewalReminder survives a real redis queue round-trip with zero tenant context on the worker side', function () {
    [$tenantId, $email] = seedActiveSubscription('Real Queue Renewal', subscriptionOverrides: [
        'renews_at' => today()->addDays(10)->setTime(14, 0),
    ]);

    $this->artisan('billing:send-renewal-reminders')->assertSuccessful();

    fireOneRealRedisJobWithNoTenantContext();

    assertRealMailSentTo($email, 'renews in 10 days');
});
