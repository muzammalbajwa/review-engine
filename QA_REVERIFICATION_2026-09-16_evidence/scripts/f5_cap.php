<?php

use App\Jobs\SendReviewRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

$ids = json_decode(file_get_contents(getenv('AUDIT_IDS')), true);
$contact = (int) getenv('AUDIT_CONTACT');
$start = (int) getenv('AUDIT_START');
$maxHops = (int) (getenv('AUDIT_MAX_HOPS') ?: 6);
$r = Redis::connection();

$jobsFor = function (string $key, bool $zset) use ($r, $contact) {
    $items = $zset ? $r->zrange($key, 0, -1) : $r->lrange($key, 0, -1);
    $out = [];
    foreach ($items as $raw) {
        $cmd = json_decode($raw, true)['data']['command'] ?? '';
        if (preg_match('/contactId";i:'.$contact.';/', $cmd)) {
            preg_match('/skipRetryCount";i:(\d+);/', $cmd, $m);
            $out[] = ['raw' => $raw, 'skipRetryCount' => (int) ($m[1] ?? -1), 'score' => $zset ? (float) $r->zscore($key, $raw) : null];
        }
    }
    return $out;
};

$failedBefore = DB::table('failed_jobs')->count();
$maxId = DB::table('failed_jobs')->max('id') ?? 0;
printf("[%s] MAX_SKIP_RETRIES=%d; dispatching contact %d with skipRetryCount=%d; failed_jobs=%d\n", now()->toTimeString(), SendReviewRequest::MAX_SKIP_RETRIES, $contact, $start, $failedBefore);
SendReviewRequest::dispatch($contact, 1, $start);

$expected = $start;
for ($hop = 1; $hop <= $maxHops; $hop++) {
    // Wait for exactly one outcome of the job carrying $expected: a failure, or a
    // delayed retry whose count differs from it (higher, or reset).
    $deadline = microtime(true) + 30;
    do {
        usleep(200_000);
        $delayed = array_values(array_filter(
            $jobsFor('queues:default:delayed', true),
            fn ($d) => $d['score'] > time() + 30
        ));
        $failedNow = DB::table('failed_jobs')->count();
    } while ($delayed === [] && $failedNow === $failedBefore && microtime(true) < $deadline);

    if ($failedNow > $failedBefore) {
        $row = DB::table('failed_jobs')->where('id', '>', $maxId)->orderBy('id')->first();
        printf("[%s] hop %d: job processed -> FAILED. failed_jobs %d -> %d. queue=%s failed_at=%s\n  exception: %s\n", now()->toTimeString(), $hop, $failedBefore, $failedNow, $row->queue, $row->failed_at, strtok($row->exception, "\n"));
        sleep(3);
        printf("[%s] after give-up: ready jobs for contact=%d, delayed jobs for contact=%d\n", now()->toTimeString(), count($jobsFor('queues:default', false)), count($jobsFor('queues:default:delayed', true)));
        exit;
    }

    if ($delayed === []) {
        printf("[%s] hop %d: no delayed retry and no failure within 30s — job ended silently\n", now()->toTimeString(), $hop);
        exit;
    }

    foreach ($delayed as $d) {
        printf("[%s] hop %d: processed -> requeued as delayed job with skipRetryCount=%d, due in %.1f min\n", now()->toTimeString(), $hop, $d['skipRetryCount'], ($d['score'] - time()) / 60);
    }

    if (getenv('AUDIT_NO_ACCELERATE')) {
        exit;
    }

    // Simulate the 60-minute wait: make the delayed retry due now.
    foreach ($delayed as $d) {
        $r->zadd('queues:default:delayed', [$d['raw'] => time() - 1]);
    }
}
echo "stopped after {$maxHops} hops without a failure\n";
