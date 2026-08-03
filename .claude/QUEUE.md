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

## Monitoring
- Horizon dashboard for queue health.
- Heartbeat alert if the worker dies — a silent worker means every tenant's
  reviews stop. This alert ships in Phase 2, not later.
