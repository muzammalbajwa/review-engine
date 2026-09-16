<?php

// Real-clock observer: dispatches one job near the cap for a contact in a
// default-hours tenant, then records every hop (retry count + due time in the
// tenant's timezone) until the job fails or the virtual deadline passes.

use App\Jobs\SendReviewRequest;
use App\Models\Contact;
use App\Models\TimingRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

$contactId = (int) getenv('AUDIT_CONTACT');
$start = (int) getenv('AUDIT_START');
$realSeconds = (int) getenv('AUDIT_REAL_SECONDS');
$r = Redis::connection();

$tenantId = DB::transaction(function () use ($contactId) {
    DB::statement("SELECT set_config('app.is_admin', 'true', true)");

    return Contact::withoutGlobalScopes()->find($contactId)->tenant_id;
});
$rule = DB::transaction(function () use ($tenantId) {
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

    return TimingRule::query()->first();
});
$tz = $rule->timezone;
printf("tenant %s timing rule: %s-%s %s (MAX_SKIP_RETRIES=%d)\n", $tenantId, $rule->business_hours_start, $rule->business_hours_end, $tz, SendReviewRequest::MAX_SKIP_RETRIES);

$maxId = DB::table('failed_jobs')->max('id') ?? 0;
SendReviewRequest::dispatch($contactId, 1, $start);
printf("dispatched contact %d step 1 with skipRetryCount=%d\n", $contactId, $start);

$seen = [];
$deadline = microtime(true) + $realSeconds;
while (microtime(true) < $deadline) {
    foreach ($r->zrange('queues:default:delayed', 0, -1) as $raw) {
        $p = json_decode($raw, true);
        $c = $p['data']['command'];
        if (! str_contains($c, 'contactId";i:'.$contactId.';') || isset($seen[$p['uuid']])) {
            continue;
        }
        $seen[$p['uuid']] = true;
        preg_match('/skipRetryCount";i:(\d+);/', $c, $m);
        preg_match('/"date";s:\d+:"([^"]+)"/', $c, $d);
        $due = CarbonImmutable::parse($d[1], 'UTC')->setTimezone($tz);
        printf("  hop: requeued with skipRetryCount=%s, due %s (%s)\n", $m[1] ?? '0 (default, not serialized)', $due->format('D H:i'), $due->format('H:i:s') >= $rule->business_hours_start && $due->format('H:i:s') <= $rule->business_hours_end ? 'inside hours' : 'OUTSIDE hours');
    }

    $failed = DB::table('failed_jobs')->where('id', '>', $maxId)->where('payload', 'like', '%contactId\\\\";i:'.$contactId.';%')->first();
    if ($failed) {
        printf("TERMINATED: failed_jobs id=%d (virtual failed_at %s UTC)\n  %s\n", $failed->id, $failed->failed_at, strtok($failed->exception, "\n"));
        sleep(3);
        $left = collect($r->zrange('queues:default:delayed', 0, -1))->merge($r->lrange('queues:default', 0, -1))
            ->filter(fn ($raw) => str_contains(json_decode($raw, true)['data']['command'], 'contactId";i:'.$contactId.';'))->count();
        printf("jobs still queued for contact %d afterwards: %d\n", $contactId, $left);
        exit;
    }
    usleep(200_000);
}
printf("NOT TERMINATED within %ds real time; %d requeues observed\n", $realSeconds, count($seen));
