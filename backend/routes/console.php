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

// .claude/QUEUE.md mechanic #3: "A scheduled command runs every 20 min,
// releases the next 2-3 pending contacts... Never blast." This is what
// actually turns a 'pending' contact into a real first send — previously
// nothing did. Laravel's frequency helpers jump straight from
// everyFifteenMinutes() to everyThirtyMinutes() — no everyTwentyMinutes()
// exists — so this is a literal cron expression instead of the wrong
// nearby helper. withoutOverlapping: if a run is still dispatching past
// 20 minutes, the next tick skips rather than double-releasing the same
// batch of contacts.
Schedule::command('drip:release-pending')->cron('*/20 * * * *')->withoutOverlapping();

// 7-day free trial (decision doc): finds tenants whose trial_ends_at has
// passed and transitions them to 'trial_expired'. Daily, not more
// frequent — unlike the drip release (where a customer is waiting on an
// actual send), a few hours' delay transitioning an already-past-due
// trial has no real consequence, so this doesn't need the same
// tight cadence gbp:sync-reviews or drip:release-pending do.
// withoutOverlapping: same reasoning as every other scheduled command
// here — if a run somehow takes past 24h, the next tick skips rather
// than piling up concurrent runs.
Schedule::command('trial:expire')->daily()->withoutOverlapping();

// billing:send-renewal-reminders (.claude/BILLING.md "Renewal reminders")
// removed with the Lemon Squeezy-backed Subscription model it read
// current_period_end from — see the deleted
// app/Console/Commands/SendRenewalReminders.php (recoverable from git
// history) for the full command. Re-add this schedule entry once a
// Paddle-backed equivalent exists.
