<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

$S = getenv('S');
$since = trim(file_get_contents("$S/retry_started_at.txt"));
$worker = file_get_contents("$S/worker-legacy-after.log");
$appLog = collect(file("$S/laravel-tail.log"))
    ->filter(fn ($l) => str_starts_with($l, '[2026-09-16 ') && substr($l, 12, 8) >= $since);
$r = Redis::connection();
$delayed = collect($r->zrange('queues:default:delayed', 0, -1))->map(fn ($raw) => [
    'cmd' => json_decode($raw, true)['data']['command'],
    'due_min' => round(($r->zscore('queues:default:delayed', $raw) - time()) / 60),
]);

$rows = [];
foreach (file("$S/retry_set.txt", FILE_IGNORE_NEW_LINES) as $line) {
    [$uuid, $contact, $step] = explode(' ', $line);
    preg_match_all("/{$uuid} redis default\s+(?:[\d.]+ms )?(DONE|FAIL)/", $worker, $m);
    $skip = $appLog->first(fn ($l) => str_contains($l, "\"contact_id\":{$contact},\"step\":{$step},"));
    preg_match('/"reason":"([a-z_]+)"(?:,"skip_retry_count":(\d+))?/', (string) $skip, $sm);
    $next = $delayed->first(fn ($d) => str_contains($d['cmd'], "contactId\";i:{$contact};") && str_contains($d['cmd'], "s:4:\"step\";i:{$step};"));
    preg_match('/skipRetryCount";i:(\d+);/', $next['cmd'] ?? '', $nm);
    $rows[] = [
        'uuid' => substr($uuid, 0, 8),
        'contact' => (int) $contact,
        'step' => (int) $step,
        'worker' => implode(',', $m[1]) ?: 'not run',
        'still_in_failed_jobs' => DB::table('failed_jobs')->where('uuid', $uuid)->exists(),
        'outcome' => $sm[1] ?? ($next ? 'redelayed (outside business hours)' : 'none logged'),
        'requeued' => $next ? "skipRetryCount=".($nm[1] ?? '0')." due+{$next['due_min']}m" : '-',
    ];
}

foreach ($rows as $row) {
    echo implode(' | ', array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : $v, $row)), "\n";
}
$c = collect($rows);
printf("\nTOTAL %d | worker DONE only: %d | any FAIL: %d | still in failed_jobs: %d\n",
    $c->count(), $c->where('worker', 'DONE')->count(), $c->filter(fn ($x) => str_contains($x['worker'], 'FAIL'))->count(), $c->where('still_in_failed_jobs', true)->count());
echo 'outcomes: '.json_encode($c->countBy('outcome')), "\n";
echo 'new crash rows since retry: '.DB::table('failed_jobs')->where('failed_at', '>=', '2026-09-16 '.$since)->where('exception', 'like', '%skipRetryCount must not be accessed%')->count(), "\n";
