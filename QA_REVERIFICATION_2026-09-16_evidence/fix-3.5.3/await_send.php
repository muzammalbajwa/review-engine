<?php

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

// Waits for a contact's step to be sent. If a delayed skip-retry for it is
// waiting, makes it due now (the only simulated part: the 60-minute wait).
$ids = json_decode(file_get_contents(getenv('AUDIT_IDS')), true);
$contact = $ids[getenv('AUDIT_WHICH')];
$step = 2;
$accelerate = (bool) getenv('AUDIT_ACCELERATE');
$r = Redis::connection();

$status = fn () => DB::transaction(function () use ($ids, $contact, $step) {
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $ids['tenant']]);

    return Message::query()->where('contact_id', $contact['id'])->where('step', $step)->first(['status', 'updated_at']);
});
$mails = fn () => collect(Http::get('http://127.0.0.1:8025/api/v1/search', ['query' => 'to:"'.$contact['email'].'"'])->json('messages'))
    ->map(fn ($m) => "{$m['ID']} | {$m['Subject']} | {$m['Created']}")->all();

$deadline = microtime(true) + 30;
while (microtime(true) < $deadline) {
    if ($accelerate) {
        foreach ($r->zrange('queues:default:delayed', 0, -1) as $raw) {
            $c = json_decode($raw, true)['data']['command'];
            if (str_contains($c, 'contactId";i:'.$contact['id'].';') && str_contains($c, 's:4:"step";i:'.$step.';')) {
                $r->zadd('queues:default:delayed', [$raw => time() - 1]);
            }
        }
    }
    $m = $status();
    if ($m?->status === 'sent' && $mails() !== []) {
        printf("[%s] SENT: contact %d step %d message status=sent (updated %s); mails: %s\n", now()->format('H:i:s'), $contact['id'], $step, $m->updated_at, json_encode($mails()));
        exit;
    }
    usleep(300_000);
}
printf("[%s] NOT SENT within 30s: message=%s mails=%s\n", now()->format('H:i:s'), json_encode($status()), json_encode($mails()));
