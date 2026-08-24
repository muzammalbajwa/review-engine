<?php

use App\Console\Commands\ReleasePendingContacts;
use App\Jobs\SendReviewRequest;
use App\Mail\ReviewRequestMail;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Queue\BusinessHoursGate;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

/**
 * Sleep::fake() is process-wide static state, not scoped to a test the way
 * DatabaseTransactions scopes the DB — left faked, it would silently fake
 * every real sleep()/usleep() call in every test that runs after this
 * file, including in code that has nothing to do with this file. Reset
 * unconditionally, not just on the happy path (a mid-test failure must
 * not leave it faked for whatever runs next).
 */
afterEach(function () {
    Sleep::fake(false);
});

/**
 * .claude/QUEUE.md mechanic #3: "A scheduled command runs every 20 min,
 * releases the next 2-3 pending contacts... ONLY if within the tenant's
 * local business hours + timezone. Never blast." routes/console.php
 * registers this on `*\/20 * * * *`; this file tests the command's own
 * release/gating logic in isolation.
 */
test('releases up to 3 oldest pending contacts for a tenant within business hours', function () {
    [$token, $tenantId] = seedCustomerAccount('Release Batch');
    setTenantBusinessHoursWideOpen($tenantId);

    $contactIds = [];
    foreach (range(1, 5) as $i) {
        $contactIds[] = makeQuickAddContact($token, "Contact {$i}", "contact{$i}@example.com");
    }

    Bus::fake([SendReviewRequest::class]);

    $this->artisan('drip:release-pending')->assertExitCode(0);

    Bus::assertDispatchedTimes(SendReviewRequest::class, 3);
    // The three oldest, not an arbitrary three.
    foreach (array_slice($contactIds, 0, 3) as $expectedId) {
        Bus::assertDispatched(SendReviewRequest::class, fn (SendReviewRequest $job) => $job->contactId === $expectedId && $job->step === 1);
    }
    foreach (array_slice($contactIds, 3) as $notYetId) {
        Bus::assertNotDispatched(SendReviewRequest::class, fn (SendReviewRequest $job) => $job->contactId === $notYetId);
    }
});

test('a tenant outside business hours gets nothing released this tick', function () {
    [$token, $tenantId] = seedCustomerAccount('Release Closed');
    setTenantBusinessHoursClosed($tenantId);
    makeQuickAddContact($token, 'Should Wait', 'wait@example.com');

    Bus::fake([SendReviewRequest::class]);

    $this->artisan('drip:release-pending')->assertExitCode(0);

    Bus::assertNotDispatched(SendReviewRequest::class);
});

test('two tenants are released independently — one at their cap never blocks the other', function () {
    [$tokenA, $tenantA] = seedCustomerAccount('Release Tenant A');
    [$tokenB, $tenantB] = seedCustomerAccount('Release Tenant B');
    setTenantBusinessHoursWideOpen($tenantA);
    setTenantBusinessHoursWideOpen($tenantB);

    foreach (range(1, 3) as $i) {
        makeQuickAddContact($tokenA, "A{$i}", "a{$i}@example.com");
    }
    $bContactId = makeQuickAddContact($tokenB, 'B1', 'b1@example.com');

    Bus::fake([SendReviewRequest::class]);

    $this->artisan('drip:release-pending')->assertExitCode(0);

    Bus::assertDispatchedTimes(SendReviewRequest::class, 4);
    Bus::assertDispatched(SendReviewRequest::class, fn (SendReviewRequest $job) => $job->contactId === $bContactId);
});

test('a contact already past pending is never re-released', function () {
    [$token, $tenantId] = seedCustomerAccount('Release Already Sent');
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Already Sent', 'alreadysent@example.com');

    DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        Contact::find($contactId)->update(['status' => 'sent']);
    });

    Bus::fake([SendReviewRequest::class]);

    $this->artisan('drip:release-pending')->assertExitCode(0);

    Bus::assertNotDispatched(SendReviewRequest::class);
});

/**
 * CRITICAL — reproduces, on demand and deterministically, a real bug found
 * live during a 2026-08-06 concurrency audit: releaseForTenant() selects
 * `status = 'pending'` contacts with no row locking (no lockForUpdate/
 * SKIP LOCKED) and no pre-dispatch status change (see the command's own
 * docblock). Nothing about a contact's state changes between two
 * back-to-back drip:release-pending ticks unless a dispatched job has
 * actually *run* — so two ticks that both fire before either one's job
 * runs will both select and dispatch the same contact. Confirmed live
 * with two genuinely concurrent OS processes against the real dev
 * database (the log carried a "step_already_sent" skip for the second of
 * each pair); this test reproduces the identical mechanism
 * deterministically, without relying on real timing/OS concurrency —
 * flaky-free and CI-safe, because the race isn't actually about
 * *timing*, it's about *ordering*: as long as both SELECTs happen before
 * either dispatched job runs, the outcome is the same every time.
 *
 * Two phases:
 *  1. Force the actual race — Bus::fake() freezes exactly the window
 *     where it happens (both ticks' SELECTs see the same 'pending' row,
 *     because dispatching under a fake never runs the job that would
 *     flip it to 'sent'). Assert the same contact was genuinely
 *     dispatched twice, not once — the race itself, proven, not assumed.
 *  2. Run both of those real dispatches for real, sequentially — exactly
 *     what Horizon actually does: SendReviewRequest's own
 *     WithoutOverlapping middleware (see its middleware() method) prevents
 *     two instances from running *simultaneously*, releasing the second
 *     back to the queue until the first finishes, not from both
 *     eventually running — so sequential is the realistic case, not a
 *     weakened stand-in for it. Confirms the messages.UNIQUE(tenant_id,
 *     contact_id, step) + firstOrCreate() + explicit status check
 *     backstop holds: exactly one real send, one message row, one
 *     'sent' contact — no duplicate, no error, no orphaned state.
 *
 * Still double-dispatches even with releaseForTenantWithLock()'s
 * Cache::lock() now in place, deliberately: two calls to
 * $this->artisan(...) here run one fully to completion (acquire, work,
 * release) before the next begins — never overlapping — so the lock has
 * nothing to contend on and correctly lets both through. That's expected,
 * not a gap: the lock only stops genuinely *concurrent* callers from both
 * seeing the same 'pending' row; it can't and shouldn't stop two
 * back-to-back calls from re-finding a contact whose status genuinely
 * hasn't changed yet. This test's job is proving the backstop remains
 * necessary regardless — the lock-specific proof (a held lock actually
 * skipping, and actually expiring on its own) lives in the two tests
 * below.
 */
test('CRITICAL: two racing drip:release-pending ticks double-dispatch the same contact, and SendReviewRequest\'s own idempotency backstop absorbs it — exactly one real send results', function () {
    [$token, $tenantId] = seedCustomerAccount('Release Race');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Race Contact', 'race-contact@example.com');

    // Phase 1: force the race.
    Bus::fake([SendReviewRequest::class]);

    $this->artisan('drip:release-pending')->assertExitCode(0);
    $this->artisan('drip:release-pending')->assertExitCode(0);

    $dispatchedForThisContact = Bus::dispatched(SendReviewRequest::class)
        ->filter(fn (SendReviewRequest $job) => $job->contactId === $contactId && $job->step === 1);

    expect($dispatchedForThisContact)->toHaveCount(2);

    // Phase 2: run both real dispatches for real, sequentially.
    Mail::fake();

    (new SendReviewRequest($contactId, 1))->handle();
    (new SendReviewRequest($contactId, 1))->handle();

    Mail::assertSentTimes(ReviewRequestMail::class, 1);

    $messages = DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Message::where('contact_id', $contactId)->where('step', 1)->get();
    });
    expect($messages)->toHaveCount(1);
    expect($messages->first()->status)->toBe('sent');

    $contact = DB::transaction(function () use ($tenantId, $contactId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Contact::find($contactId);
    });
    expect($contact->status)->toBe('sent');
});

/**
 * releaseForTenantWithLock() is private — invoked via reflection, the
 * same established convention this suite already uses for testing an
 * internal that has no public surface of its own (see
 * MiddlewareOrderingTest.php's use of ReflectionMethod on the router).
 */
function invokeReleaseForTenantWithLock(string $tenantId): int
{
    $command = app(ReleasePendingContacts::class);
    $ref = new ReflectionMethod($command, 'releaseForTenantWithLock');
    $ref->setAccessible(true);

    return $ref->invoke($command, $tenantId, app(BusinessHoursGate::class));
}

/**
 * CRITICAL — the fix for the race proven in the test above. Simulates
 * genuine overlap directly: rather than relying on real OS-level timing
 * (which the double-dispatch test above deliberately avoids too, for the
 * same flaky-free-and-CI-safe reason), this test holds the exact same
 * lock key/API the command itself uses — as if another process's
 * releaseForTenant() were already mid-flight for this tenant — then
 * proves the second call backs off correctly.
 *
 * The proof that matters here isn't just "the end state is correct" (that
 * was already true before this lock existed, via SendReviewRequest's own
 * idempotency backstop — see the test above). It's that
 * Bus::assertNotDispatched() holds: releaseForTenant()'s body — the
 * SELECT and every downstream query prepare() would have triggered —
 * never ran at all for the second caller. That's the ~9-10 wasted queries
 * this lock exists to prevent, shown not happening, not assumed.
 *
 * Sleep::fake(true, true) makes block(5, ...)'s real polling loop resolve
 * instantly by advancing Carbon's clock in lockstep with each faked sleep
 * — the actual timeout condition is still reached for real, just without
 * 5 real wall-clock seconds elapsing.
 */
test('CRITICAL: a tenant whose release lock is already held is skipped entirely — zero wasted queries, not just a correct end state', function () {
    [$token, $tenantId] = seedCustomerAccount('Lock Held');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    makeQuickAddContact($token, 'Lock Held Contact', 'lock-held@example.com');

    $lock = Cache::lock("drip:release-pending:{$tenantId}", 60);
    expect($lock->get())->toBeTrue();

    Sleep::fake(true, true);
    Bus::fake([SendReviewRequest::class]);
    Log::shouldReceive('warning')->once()->withArgs(
        fn ($message, $context) => str_contains($message, 'could not acquire release lock')
            && $context['tenant_id'] === $tenantId
    );

    $released = invokeReleaseForTenantWithLock($tenantId);

    expect($released)->toBe(0);
    Bus::assertNotDispatched(SendReviewRequest::class);

    $lock->release();
});

/**
 * The other half of "prove it, don't declare it": a lock that's never
 * released (the process holding it crashed, was killed mid-job, whatever)
 * must not permanently strand a tenant. Acquires the real lock via the
 * exact same Cache::lock() API the command uses — not a hand-crafted
 * cache_locks row — then time-travels past its real TTL without ever
 * calling ->release(), simulating exactly that crash.
 * DatabaseLock::acquire()'s own fallback path (UPDATE ... WHERE owner = ?
 * OR expiration <= now()) is what makes a fresh acquire succeed here —
 * this is that mechanism firing for real, not a mock standing in for it.
 *
 * Reads LOCK_TIMEOUT_SECONDS off the real class via reflection rather
 * than hardcoding a second copy of "60" here — a hardcoded duplicate
 * would keep passing even if the constant were ever changed without this
 * test being updated to match, silently testing a TTL the app no longer
 * actually uses.
 */
test('CRITICAL: a stuck lock clears on its own once it expires — a crashed process cannot permanently strand a tenant', function () {
    [$token, $tenantId] = seedCustomerAccount('Lock Stuck');
    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);
    setTenantBusinessHoursWideOpen($tenantId);
    $contactId = makeQuickAddContact($token, 'Lock Stuck Contact', 'lock-stuck@example.com');

    $timeoutSeconds = (new ReflectionClassConstant(ReleasePendingContacts::class, 'LOCK_TIMEOUT_SECONDS'))->getValue();
    expect($timeoutSeconds)->toBeInt()->toBeGreaterThan(0);

    $crashedLock = Cache::lock("drip:release-pending:{$tenantId}", $timeoutSeconds);
    expect($crashedLock->get())->toBeTrue();
    // Deliberately never released — simulates the holding process dying
    // mid-job, the exact scenario a badly-tuned permanent lock could turn
    // into a self-inflicted outage for.

    $this->travel($timeoutSeconds + 1)->seconds();

    Bus::fake([SendReviewRequest::class]);

    $released = invokeReleaseForTenantWithLock($tenantId);

    // The tenant was NOT permanently stranded: a fresh acquire succeeded
    // once the stuck lock's own expiration passed, and the real release
    // work happened for real.
    expect($released)->toBe(1);
    Bus::assertDispatched(SendReviewRequest::class, fn (SendReviewRequest $job) => $job->contactId === $contactId && $job->step === 1);
});
