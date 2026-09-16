# QUEUE — the scheduling engine (Laravel Queues + Horizon + Redis)

## Why Laravel Queues, not BullMQ
The product is one backend (Laravel). BullMQ is Node-only and would force a
second language/service. Laravel Queues + Horizon do everything we need.

## The three mechanics
1. Delayed follow-up (msg2 → msg3):
   dispatch(new SendReviewRequest($contact))->delay(now()->addMinutes($n));
   The msg3 job, when it runs, FIRST checks the DB: did the contact click or
   review? If yes → return (do nothing). If no → send. This is the
   compliant follow-up. The check lives in the job, not the schedule.

2. Cancel a follow-up when a review lands:
   Store the job on the contact. When a review/click arrives, delete the
   pending job so nobody is messaged twice.

3. Reactivation drip (2–3 per 20 min, business hours only):
   A scheduled command runs every 20 min, releases the next 2–3 pending
   contacts, but ONLY if within the tenant's local business hours + timezone.
   Never blast. Timezone is per-tenant, stored on timing_rules.

## Mandatory job settings
- Idempotency: unique job key per (contact_id, step). Never double-send.
- Retries: tries=3, exponential backoff.
- withoutOverlapping locks on the drip command so two workers don't
  double-release.
- Failed jobs go to failed_jobs table + alert.
- A self-requeuing skip (waiting on a prerequisite — no verified sender,
  no connected GBP, no compliance-passed template, no email channel at
  all) has a finite cap (SendReviewRequest::MAX_SKIP_RETRIES, 168 tries
  at the 60-minute retry interval — roughly a week), not an infinite
  loop. Once exceeded, the job calls $this->fail() with a real reason —
  it lands in failed_jobs and fires the same "fail loud, not silent"
  alert (App\Notifications\ReviewRequestSendFailed) a genuine exception
  would.

## Queue priority — transactional kept separate from default
Two named queues on the same Redis connection, not one:
- `transactional` — every account-critical or time-sensitive
  notification: email verification, welcome, team invites,
  sender-identity verification, GBP disconnect alerts, subscription
  renewal reminders, and the ops alert for a permanently failed review
  request (ReviewRequestSendFailed). Each of these sets
  `->onQueue('transactional')` in its own constructor (see
  App\Notifications\VerifyEmailAddress for the full rationale).
- `default` — everything else, including SendReviewRequest's bulk sends
  and its own self-requeuing retries.

How `transactional` stays ahead depends on what runs the workers:
- **`queue:work --queue=transactional,default`** (composer.json's `dev`
  script): one worker checks the queues in the order listed before each
  job, so every waiting `transactional` job is taken before any
  `default` job. Strict priority on a single worker.
- **Horizon (production)**: config/horizon.php's supervisor lists both
  queues with `balance => 'auto'`. Under auto-balancing Horizon does not
  use the listed order as a priority; it runs a separate worker pool per
  queue, each with at least `minProcesses` (default 1) process, and
  scales the pools by workload. `transactional` therefore always has at
  least one dedicated worker that never picks up `default` jobs, however
  large the `default` backlog. Verified live on 2026-09-16: `ps` showed
  one `horizon:work --queue=transactional` process next to the
  `--queue=default` ones, and a verification email queued behind 3,000
  drip jobs was delivered in about 2.6s. If `balance` is ever set to
  `false`, Horizon falls back to the single-worker listed-order behaviour
  above.

Without this split, a backlog of low-value drip retries for one tenant's
badly-set-up contacts could delay a completely different tenant's
account email on the shared workers — a real, observed risk before both
this and the retry cap above were added (a QA audit found a
verification-email job sitting behind dozens of drip-retry jobs in the
live Redis queue).

## Monitoring
- Horizon dashboard for queue health.
- Heartbeat alert if the worker dies — a silent worker means every tenant's
  reviews stop. This alert ships in Phase 2, not later.
