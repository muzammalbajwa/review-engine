<?php

use App\Jobs\SendReviewRequest;
use Illuminate\Support\Facades\Queue;

$ids = json_decode(file_get_contents(getenv('AUDIT_IDS')), true)['backlog_contacts'];
$n = (int) getenv('AUDIT_N');

$t = microtime(true);
for ($i = 0; $i < $n; $i++) {
    SendReviewRequest::dispatch($ids[$i % count($ids)], 1, $i % 5);
}
printf("pushed %d SendReviewRequest jobs to default in %.2fs; default size now %d\n", $n, microtime(true) - $t, Queue::connection('redis')->size('default'));
