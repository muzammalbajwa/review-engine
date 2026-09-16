<?php

use App\Jobs\SendReviewRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

$contact = (int) getenv('AUDIT_CONTACT');
$r = Redis::connection();

// Build a real payload, then remove skipRetryCount so it matches what the
// pre-951f883 code serialized for jobs already sitting in Redis at deploy time.
SendReviewRequest::dispatch($contact, 2)->onQueue('audit-legacy-build');
$raw = $r->lpop('queues:audit-legacy-build');
$payload = json_decode($raw, true);
$cmd = $payload['data']['command'];
$legacy = preg_replace('/s:14:"skipRetryCount";i:\d+;/', '', $cmd, 1, $n);
$legacy = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn ($m) => "O:{$m[1]}:\"{$m[2]}\":".($m[3] - 1).':', $legacy);
$payload['data']['command'] = $legacy;

echo "removed skipRetryCount from payload: {$n}\n";
echo "legacy command: {$legacy}\n";

$before = DB::table('failed_jobs')->count();
$r->rpush('queues:default', json_encode($payload));

sleep(4);
$row = DB::table('failed_jobs')->orderByDesc('id')->first();
printf("failed_jobs %d -> %d\nnewest failure: %s\n", $before, DB::table('failed_jobs')->count(), strtok($row->exception, "\n"));
