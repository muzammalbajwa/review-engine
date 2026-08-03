<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// .claude/ROADMAP.md Phase 3: review sync. No cadence is specified in the
// docs (QUEUE.md's "every 20 min" cadence is specifically the reactivation
// drip, a different job) — 15 minutes is a reasonable balance between
// near-real-time review awareness and not hammering Google's API.
// withoutOverlapping: if a sync run is still dispatching/running past 15
// minutes (many connections, slow API), the next tick skips rather than
// piling up concurrent runs of this same command.
Schedule::command('gbp:sync-reviews')->everyFifteenMinutes()->withoutOverlapping();

// .claude/QUEUE.md: worker heartbeat. Every minute — this is the "every
// tenant's reviews just silently stopped" signal, so it should page fast,
// not on the same slower cadence as ordinary background work.
Schedule::command('queue:check-heartbeat')->everyMinute()->withoutOverlapping();
