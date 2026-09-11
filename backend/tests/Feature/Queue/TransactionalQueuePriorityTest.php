<?php

use App\Jobs\SendReviewRequest;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * QA-audit Finding 5 (2nd half) permanent regression guard — the exact
 * test the audit itself demanded: "with a deliberately large backlog of
 * drip-retry jobs queued, confirm a transactional notification queued
 * afterward is still processed promptly, not stuck behind the backlog —
 * real evidence, not assumed from the queue priority configuration
 * alone."
 *
 * This pushes a real, large number of real App\Jobs\SendReviewRequest
 * instances onto the real 'default' redis queue (never fired here —
 * SendReviewRequest's own correctness is covered by
 * tests/Feature/Queue/SendReviewRequestTest.php; this file is only about
 * queue ORDERING), queues one real transactional notification
 * afterward, then runs the exact real command line this app is
 * documented to run — composer.json's `dev` script and
 * config/horizon.php's default supervisor both use
 * `--queue=transactional,default` — via `php artisan queue:work --once`.
 * `--once` makes it terminate after exactly one job instead of
 * daemonizing, so this test can assert on precisely which ONE job a
 * real worker picks first out of hundreds sitting on the queue: the
 * transactional one, or the ${backlogSize}-deep backlog ahead of it by
 * insertion order.
 *
 * pendingSize(), not size(): RedisQueue::size() sums ready + delayed +
 * reserved together (confirmed directly — it evals LuaScripts::size()
 * against all three keys). This local dev/test Redis carries a large,
 * genuinely pre-existing 'default:delayed' backlog from earlier manual
 * QA sessions (thousands of entries) that migrates into the ready list
 * a little at a time on every single pop() call (RedisQueue's own
 * migrate-expired-delayed-jobs step) — a size()-based "did I put back
 * exactly what I took out" check drifts against that constantly moving
 * target and both over- and under-counts depending on timing, causing
 * real, observed test flakiness. pendingSize() (a plain LLEN on the
 * ready list alone) isolates this test from that entirely — it only
 * ever reflects jobs sitting immediately ready to pop, which is the
 * only thing "does priority ordering skip past the backlog" actually
 * depends on.
 *
 * mail.default is the 'array' transport (config/mail.php), not
 * Mail::fake() — see
 * tests/Feature/Queue/NotificationsSurviveRealQueueWorkerTest.php's own
 * docblock for why Mail::fake() would silently record nothing here: a
 * real Mailer/MailChannel build with a real Symfony Email object at the
 * end of it, just collected in memory instead of opening a real SMTP
 * connection.
 */
beforeEach(function () {
    config(['queue.default' => 'redis']);
    config(['mail.default' => 'array']);
    app('mail.manager')->mailer('array')->getSymfonyTransport()->flush();

    // 'transactional' is meant to stay near-empty in real operation (it's
    // the whole point of splitting it from 'default') — but if anything
    // genuinely pending is sitting there already (a real leftover from a
    // manual session, or a previous test run that didn't finish
    // draining), this test's own --once run later could pop THAT job
    // instead of the one it just queued, and fail for the wrong reason:
    // "priority is broken" when the real story is "an unrelated job was
    // ahead of this test's own in the same real, shared queue." Draining
    // it for real (firing each one, not deleting) before this test's own
    // logic starts is the correct thing to do either way — these are
    // real pending transactional notifications that should be delivered,
    // not discarded — and it's what makes this test deterministic
    // regardless of what ran before it. Bounded so a genuine bug
    // (something wedged, re-queueing itself) can't hang the suite.
    for ($i = 0; $i < 50 && Queue::connection('redis')->pendingSize('transactional') > 0; $i++) {
        $job = Queue::connection('redis')->pop('transactional');

        if ($job === null) {
            break;
        }

        $job->fire();
    }
});

test('CRITICAL: a transactional notification queued after a large drip-retry backlog is still processed first, not stuck behind it', function () {
    $backlogSize = 300;
    $defaultReadyBefore = Queue::connection('redis')->pendingSize('default');

    try {
        // A deliberately large, REAL backlog on the exact queue
        // SendReviewRequest's bulk sends/retries actually use. A
        // nonexistent contact id is fine — these jobs are never fired
        // in this test, only sat on the queue to prove ordering; firing
        // them would just make each one log-and-return
        // (contact_not_found, see SendReviewRequest::prepare()), which
        // is irrelevant to what this test checks.
        for ($i = 0; $i < $backlogSize; $i++) {
            SendReviewRequest::dispatch(-1, 1);
        }

        expect(Queue::connection('redis')->pendingSize('default'))->toBe($defaultReadyBefore + $backlogSize);

        // Queued AFTER the entire backlog above. If the queue-priority
        // fix were only a configuration change nobody actually exercised
        // (composer.json / config/horizon.php alone, unproven), a
        // --once run below would be just as likely to pop one of the
        // $backlogSize 'default' jobs pushed earlier as it would this
        // one.
        $email = 'priority-proof-'.uniqid().'@example.com';
        Notification::route('mail', $email)->notify(new VerifyEmailAddress(999999, $email));

        expect(Queue::connection('redis')->pendingSize('transactional'))->toBe(1);

        // The exact real command line this app is documented to run —
        // never a hand-rolled re-implementation of "check transactional
        // first."
        test()->artisan('queue:work', [
            'connection' => 'redis',
            '--queue' => 'transactional,default',
            '--once' => true,
        ]);

        // Real evidence the transactional job actually got built and
        // sent through the real Mailer/MailChannel pipeline.
        assertRealMailSentTo($email, 'Verify Email Address');

        // And real evidence of WHERE it came from: the 'transactional'
        // queue's one ready job is now gone (it was the one just
        // processed), while 'default' is completely untouched — still
        // exactly $backlogSize deep. If priority ordering weren't real,
        // this --once run would have popped from 'default' instead,
        // leaving 'transactional' still holding its one job and
        // 'default' one short.
        expect(Queue::connection('redis')->pendingSize('transactional'))->toBe(0);
        expect(Queue::connection('redis')->pendingSize('default'))->toBe($defaultReadyBefore + $backlogSize);
    } finally {
        // Real Redis is a shared external resource, not rolled back by
        // RefreshDatabase — drain exactly the $backlogSize jobs this
        // test pushed (a fixed, known count — never a size()-based "loop
        // until back to baseline", which this file's own docblock
        // explains is unreliable here) so the backlog never lingers into
        // another test run or a real dev worker.
        for ($i = 0; $i < $backlogSize; $i++) {
            $job = Queue::connection('redis')->pop('default');

            if ($job === null) {
                break;
            }

            $job->delete();
        }

        if (Queue::connection('redis')->pendingSize('transactional') > 0) {
            $job = Queue::connection('redis')->pop('transactional');
            $job?->delete();
        }
    }
});
