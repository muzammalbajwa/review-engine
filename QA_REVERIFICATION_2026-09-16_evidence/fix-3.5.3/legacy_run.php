<?php

use App\Jobs\SendReviewRequest;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

$ids = json_decode(file_get_contents(getenv('AUDIT_IDS')), true);
$which = getenv('AUDIT_WHICH');
$contact = $ids[$which];
$step = 2;
$r = Redis::connection();
$ts = fn () => now()->format('H:i:s');

// A real payload from the current code, with skipRetryCount removed: the exact
// shape the pre-951f883 code queued (constructor had only contactId + step).
SendReviewRequest::dispatch($contact['id'], $step)->onQueue('audit-legacy-build');
$payload = json_decode($r->lpop('queues:audit-legacy-build'), true);
$cmd = preg_replace('/s:14:"skipRetryCount";i:\d+;/', '', $payload['data']['command'], 1, $removed);
$cmd = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn ($m) => "O:{$m[1]}:\"{$m[2]}\":".($m[3] - $removed).':', $cmd);
$payload['data']['command'] = $cmd;
$uuid = $payload['uuid'];

printf("[%s] legacy payload (skipRetryCount removed: %d): %s\n", $ts(), $removed, $cmd);
printf("[%s] pushing uuid=%s for contact %d (%s) step %d onto queues:default\n", $ts(), $uuid, $contact['id'], $contact['email'], $step);
$r->rpush('queues:default', json_encode($payload));

$messageStatus = function () use ($ids, $contact, $step) {
    return DB::transaction(function () use ($ids, $contact, $step) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $ids['tenant']]);

        return Message::query()->where('contact_id', $contact['id'])->where('step', $step)->value('status');
    });
};
$mails = fn () => collect(Http::get('http://127.0.0.1:8025/api/v1/search', ['query' => 'to:"'.$contact['email'].'"'])->json('messages'))
    ->map(fn ($m) => "{$m['ID']} | {$m['Subject']}")->all();

$deadline = microtime(true) + 45;
$lastAttempts = -1;
while (microtime(true) < $deadline) {
    usleep(300_000);

    foreach ($r->zrange('queues:default:delayed', 0, -1) as $raw) {
        $p = json_decode($raw, true);
        $c = $p['data']['command'];
        if ($p['uuid'] !== $uuid
            && str_contains($c, 'contactId";i:'.$contact['id'].';')
            && str_contains($c, 's:4:"step";i:'.$step.';')
            && preg_match('/skipRetryCount";i:(\d+);/', $c, $m)) {
            printf("[%s] RESULT: no crash. Job skipped (prerequisite missing) and requeued as new job %s with skipRetryCount=%d, due in %.0f min\n", $ts(), $p['uuid'], $m[1], ($r->zscore('queues:default:delayed', $raw) - time()) / 60);
            printf("  failed_jobs row for original uuid: %s; message status=%s\n", DB::table('failed_jobs')->where('uuid', $uuid)->exists() ? 'YES' : 'none', var_export($messageStatus(), true));
            exit;
        }
        if ($p['uuid'] === $uuid) {
            if ($p['attempts'] !== $lastAttempts) {
                printf("[%s] attempt %d threw -> released for retry; fast-forwarding backoff\n", $ts(), $p['attempts']);
                $lastAttempts = $p['attempts'];
            }
            $r->zadd('queues:default:delayed', [$raw => time() - 1]);
        }
    }

    $failed = DB::table('failed_jobs')->where('uuid', $uuid)->first();
    if ($failed) {
        printf("[%s] RESULT: failed_jobs id=%d uuid=%s\n  %s\n", $ts(), $failed->id, $uuid, strtok($failed->exception, "\n"));
        printf("  message(step %d) status=%s; mails to contact: %s\n", $step, var_export($messageStatus(), true), json_encode($mails()));
        exit;
    }

    if ($messageStatus() === 'sent' && $mails() !== []) {
        printf("[%s] RESULT: SENT. message(step %d) status=sent; mails: %s\n", $ts(), $step, json_encode($mails()));
        exit;
    }
}
printf("[%s] RESULT: no failure and no send within 45s. message status=%s\n", $ts(), var_export($messageStatus(), true));
