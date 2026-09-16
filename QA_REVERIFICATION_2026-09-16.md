# ReviewEngine — Re-verification Audit of the QA Fixes

**Date:** 2026-09-16
**Scope:** An independent re-check of the fixes for every finding in `QA_AUDIT_2026-09-11.md`. I did not treat the earlier implementation summaries or code comments as proof. Every result below comes from something I ran and observed.
**Code audited:** `main` at `951f883 "QA changes"` (Findings 1–5), plus the **uncommitted** working-tree changes for Findings 6 and 7 (`GbpController.php`, `GbpOAuthTest.php`, `gbp/actions.ts`, `gbp/connect/page.tsx`, `SubscribeForm.tsx`).
**Environment:**
- Postgres 16 (local).
- Redis (local, database 0, key prefix `reviewengine-database-`).
- Mailpit on `127.0.0.1:1025` for SMTP and `:8025` for its web UI and API.
- Backend: `php artisan serve --port=8123`.
- Frontend: `next dev --experimental-https` on port 3000.
- Workers: a separate `php artisan queue:work redis --queue=transactional,default` process, and later `php artisan horizon`.
- The backend processes ran with `APP_URL=http://127.0.0.1:8123` set in the process environment, so email links pointed at the port actually in use (the original audit's Finding 8). `.env` was not edited.

**Evidence:** `QA_REVERIFICATION_2026-09-16_evidence/`
- Screenshots.
- Raw measurements: `playwright-measurements-part*.json`.
- Worker logs: `logs/`.
- Every script used: `scripts/`. The Playwright specs were run from `frontend/e2e/`, then moved here so they don't join the permanent suite.

---

## 1. Summary

| # | Finding | Verdict |
|---|---|---|
| 1 | Queued account emails silently lost under a real worker | **Fixed in the app.** All four emails were delivered to Mailpit through a real, separate worker. **The regression guard is weaker than it looks:** CI runs no tests at all, and the new test fails depending on leftover Redis state and on which test files run. |
| 2 | Marketing page scrolls sideways at 768px | **Fixed.** `scrollWidth == clientWidth` at 768 and at 7 other widths; the project's tablet visual test passes. |
| 3 | Registration form wipes every field on error | **Fixed.** Name, business name and email survived two different server-side errors; the password fields were cleared. |
| 4 | Super-admin lands on customer onboarding | **Fixed.** The seeded super-admin lands on `/admin`, and a new non-admin with unfinished onboarding still lands on `/onboarding`. |
| 5 | Bulk retries can starve transactional email; retries never stop | **Partially fixed.** Queue priority works under both `queue:work` and Horizon. **The retry cap never triggers with the default business hours** (bug found below). **The deploy also made already-queued `SendReviewRequest` jobs crash** (new bug). |
| 6 | GBP connect sends users to Google's raw error page | **Fixed for missing credentials only.** A set-but-wrong client ID still ends on Google's raw error page. |
| 7 | Checkout button disabled with no explanation | **Fixed.** With a token set, the note is hidden and the button is enabled. A real Paddle checkout could not be tested. |

**New or remaining problems found in this audit, most severe first:**

1. 🔴 **NEW (introduced by 951f883):** `SendReviewRequest` jobs queued *before* the deploy crash after it. `Typed property SendReviewRequest::$skipRetryCount must not be accessed before initialization`, then 3 attempts, then `failed_jobs`, and the review request is never sent. Reproduced on demand. The local dev database already holds **990** such rows (2026-09-11 → 2026-09-14). (§3.5.3)
2. 🔴 **Not fixed:** The retry cap (`MAX_SKIP_RETRIES = 168`) is **never reached** with default business hours. The business-hours re-delay (`SendReviewRequest.php:115`) re-dispatches without `skipRetryCount`, which resets the counter to 0 every night. Reproduced live: a job at retry 167 came back as retry **0**. (§3.5.2)
3. 🟠 **Regression guard not enforced:** No CI workflow runs any tests. `.github/workflows/deploy.yml` only deploys over SSH on every push to `main`. The new "real queue" test therefore runs nowhere automatically, under sync or Redis. (§3.1.3)
4. 🟠 **Test/dev Redis isolation:** The test suite and the dev app share one Redis database and key prefix.
   - Test runs leave real jobs behind: 2,275 `SendReviewRequest` jobs and 3 `VerifyEmailAddress` jobs were sitting in the dev queue at the start of this audit.
   - A dev worker then processes them against the *dev* database. During this audit it **sent real verification emails for test-only users** (`realgiveup-…@example.com`).
   - In the other direction, any leftover job makes `NotificationsSurviveRealQueueWorkerTest` and `SendReviewRequestTest` fail. (§3.1.4, §5)
5. 🟠 **Test depends on other test files:** `NotificationsSurviveRealQueueWorkerTest` calls `verifyUrlFor()` and `seedActiveSubscription()`, which are defined in *other* test files. Run on its own, 2 of its 4 tests error with "Call to undefined function". (§5)
6. 🟠 **Deploy never restarts workers (unverified on the server):** `deploy.yml` never runs `horizon:terminate` or `queue:restart`. A Horizon process started before 951f883 keeps its old config, which only listens on `default`. After such a deploy, every job on the new `transactional` queue (verification, welcome, invites, alerts) would sit unprocessed until Horizon is restarted by hand. I did not check the server itself. (§3.5.4)
7. 🟡 **Partial fix (Finding 6):** The new check only detects *missing or blank* credentials. A present-but-wrong client ID still sends the user to Google's "Error 401: invalid_client" page. (§3.6)
8. 🟡 **Two e2e tests can never pass as written:** `quick-add.spec.ts` and `template-compliance.spec.ts` still fail. The original audit called them "a downstream symptom of Finding 1", but that is not the real cause. Their shared test account (`e2e/global-setup.ts`) is registered and never verified, so they fail even with Finding 1 fixed and a worker running. (§4.2)
9. ⚪ **Docs:** `.claude/QUEUE.md` and `config/horizon.php` say `transactional` "fully drains before `default`". That is true for `queue:work`. Under Horizon's `balance => auto` (the production config), the real mechanism is a **dedicated worker process** for `transactional`. The outcome is good, but the docs describe the wrong mechanism. (§3.5.1)
10. ⚪ **Minor:** `ReviewRequestSendFailed` (the ops alert) goes on the `default` queue, not `transactional`. `OPS_ALERT_EMAIL` is blank in the local `.env`. If it is also blank on the server, the ops alert is skipped silently.

---

## 2. Method

- **Real processes only:** a real separate worker process and real Redis (never the sync driver), a real browser (Playwright/Chromium), and real SMTP to Mailpit. Email delivery was confirmed by reading the actual message from Mailpit's API and screenshotting it.
- **Clean start:** at the start, the dev Redis `queues:default` list held 2,275 leftover `SendReviewRequest` jobs and `queues:default:delayed` held 15 more. Their contact IDs (~49,9xx) match the *test* database's counter; the dev database's highest contact ID is 691.
  - They were **moved aside, not deleted**, to `audit-aside:2026-09-16:queues:default` and `audit-aside:2026-09-16:queues:default:delayed` in Redis, so each measurement started from a known state.
  - They are still there for you to delete or restore.
- **Simulated waits:** where the code waits a long time (a 60-minute retry delay, 60s/300s backoff), I moved the delayed job's due time to "now" in Redis instead of waiting. Everything else ran for real. Each place this was done is marked **[time accelerated]**.
- **Stubbed call:** only one external call was stubbed. Google's token endpoint returned `invalid_grant` for the GBP-revoked trigger, because real Google credentials don't exist locally. The notification it produces was still queued, and then delivered by the separate worker.

---

## 3. Per-finding results

### 3.1 Finding 1 — queued account emails

#### 3.1.1 Live delivery through a real out-of-process worker — PASS

The worker (`queue:work redis --queue=transactional,default`) ran as its own OS process for the whole test. Log: `logs/queue-work-part1.log`.

| Email | How it was triggered | Worker log | Mailpit message |
|---|---|---|---|
| `VerifyEmailAddress` | Registered `reverify-f1-1789548777227@example.com` through the **real registration form** in Chromium | `VerifyEmailAddress … redis transactional … DONE` | `1nDba0Ee2DlUvIQw1NtcCB` "Verify Email Address", 13:52:59 PKT. Screenshot: `f1-verify-email-in-mailpit.png` |
| `VerifyEmailAddress` (resend) | Clicked **"Resend verification email"** in the app (different account) | DONE | Second message for that address (`21wcS8TRFoUfX4Sl79g9kZ`). Screenshot: `f1-resend-verify-email-in-mailpit.png` |
| `WelcomeEmail` | Opened the verification link **taken from the real email** in the browser. It redirected to `https://localhost:3000/settings?verified=1` | DONE | `3mRrnC4Ckve1ByCsxxth4O` "You're verified — welcome to ReviewEngine", 13:53:02. Screenshot: `f1-welcome-email-in-mailpit.png` |
| `GbpConnectionRevoked` | A separate PHP process ran the real `GbpTokenRefresher::refreshIfNeeded()` against a real dev GBP connection row. Google's token endpoint was stubbed to `invalid_grant` (`scripts/trigger_gbp_revoked.php`) | `GbpConnectionRevoked … transactional … DONE` | `193Nxazyzl7XLkCmGBsSxY` "Your Google Business Profile connection needs attention", 13:54:11 |
| `SubscriptionRenewalReminder` | Seeded a real subscription renewing on 2026-09-26 (`scripts/seed_renewal.php`), then ran the real `php artisan billing:send-renewal-reminders` as its own process | 2× `SubscriptionRenewalReminder … DONE` | `1J76EIhGTDp5j1GVAYheCP` "Your ReviewEngine plan renews in 10 days", 13:54:12 |

The command reported "Sent 2" because one older dev subscription also renews in 10 days, so it got a reminder too.

**Realism caveat:** the GBP-revoked email wasn't triggered by a real Google revocation, which needs real Google OAuth credentials. The part the bug was about (queuing the notification, then a separate worker delivering it) was real.

#### 3.1.2 Code check

- No `$model->notify(...)` call remains anywhere in `app/`. Every queued notification now uses `Notification::route('mail', …)`.
- `SyncReviewsForConnection` and `SendReviewRequest` queue only integer IDs, never models.
- The test database user is `app_user` (not a superuser), so row-level security really applies when the permanent test clears the tenant context.

#### 3.1.3 Does the permanent test run against a non-sync queue in CI? — **NO CI EXISTS**

- `NotificationsSurviveRealQueueWorkerTest` sets `queue.default = redis` itself in `beforeEach`, overriding `.env.testing`'s `QUEUE_CONNECTION=sync`. It then takes the job off the real Redis queue and runs it with the tenant context cleared. **In design, it does use a non-sync connection.**
- It does not start a separate worker process. Clearing the tenant context stands in for a fresh worker, which is reasonable, but it is not a second process.
- **There is no CI test job.** The only workflow, `.github/workflows/deploy.yml`, SSHes to the server and runs `composer install`, `migrate`, and cache commands on every push to `main`. It never runs `php artisan test` or Playwright, and nothing blocks a deploy on a failing test. The gap that let Finding 1 ship is still open: the guard exists, but only runs if someone runs it by hand.

#### 3.1.4 Side effect seen live

When the worker started, it immediately processed 3 `VerifyEmailAddress` jobs left in the shared dev queue by the **test suite**. It sent real verification emails to `realgiveup-6aaa52d843b83@example.com` and two similar addresses, which exist only in the test database (Mailpit, 13:48:35). See finding 4 in the summary.

### 3.2 Finding 2 — tablet horizontal scroll — PASS

- **Project test:** `e2e/marketing-visual.spec.ts` "marketing page — tablet (iPad Mini, 768×1024) › renders with no horizontal overflow" passed in the full-suite run (§4.2).
- **My own measurement:** I measured `document.documentElement.scrollWidth` against `clientWidth` directly, the same way the original audit did. I also listed every element whose right edge extends past the viewport.

| Width | scrollWidth | clientWidth | body.scrollWidth | Overflowing elements |
|---|---|---|---|---|
| 360 | 360 | 360 | 360 | none |
| 390 | 390 | 390 | 390 | none |
| 640 | 640 | 640 | 640 | none |
| 700 | 700 | 700 | 700 | none |
| **768** | **768** | **768** | **768** | **none** (was 818 vs 768) |
| 820 | 820 | 820 | 820 | none |
| 1024 | 1024 | 1024 | 1024 | none |
| 1280 | 1280 | 1280 | 1280 | none |

- **Screenshots:** `f2-home-768-full.png` and `f2-footer-768.png`.
- **Code:** `frontend/app/page.tsx:433–438` has the contact column wrapped in `min-w-0`, with `break-all` on `support@reviewengine24.com`.

### 3.3 Finding 3 — registration form refill — PASS

I used the real form in Chromium, with **two different server-side errors** in a row:

| Step | Error shown | name | business_name | email | password / confirmation |
|---|---|---|---|---|---|
| 1. Mismatched confirmation | "…confirmation does not match" | kept | kept | kept | both empty ✔ |
| 2. Email already in use (`admin@reviewengine.test`) | "This email is already registered." (under the field and at the form level) | kept | kept | kept (`admin@reviewengine.test`) | both empty ✔ |
| 3. Corrected email | — | — | — | — | Registered and redirected to `/onboarding` |

- **Screenshots:** `f3-error1-password-mismatch.png` and `f3-error2-duplicate-email.png`.
- **Project tests:** `registration.spec.ts`'s own mismatch and duplicate tests pass when run on their own (3/3). In the full-suite run the mismatch test hit the register rate limit; see §4.2.
- **Note:** my first attempt wrongly read the form before the server had replied, because a broad text match hit the page's own "Already have an account?" link. That was fixed by waiting for the exact error message. It was not an app bug.

### 3.4 Finding 4 — post-login landing — PASS (both cases)

| Account | Login | Landed on |
|---|---|---|
| Seeded super-admin `admin@reviewengine.test` (`DemoAccountsSeeder`) | Real login form | **`/admin`**; the page shows the All tenants list (`f4-admin-after-login.png`) |
| Same admin session, opening `/dashboard` directly | — | Redirected to **`/admin`** |
| New non-admin (`playwright-reverify-resend-…`), onboarding not finished | Real login form | **`/onboarding`** (`f4-nonadmin-after-login.png`) |

### 3.5 Finding 5 — queue priority and retry cap

#### 3.5.1 Transactional email behind a real backlog — PASS

Setup (`scripts/f5_setup.php`, `scripts/f5_backlog.php`):
- 50 contacts with no email address, in a tenant whose business hours were set to 24 hours.
- 3,000 real `SendReviewRequest` jobs pushed to `default`.
- A real "Resend verification email" call (`POST /email/verification-notification`) made straight after.

| Worker | Email arrived after | Backlog jobs processed before it | Backlog still waiting when it arrived |
|---|---|---|---|
| `queue:work --queue=transactional,default` (the dev script) | **0.39 s** | 191 | ~2,809 |
| `php artisan horizon` (the production config) | **2.64 s** | — | 2,478 |

- **Worker log:** the `VerifyEmailAddress … transactional … DONE` line comes between two `SendReviewRequest` lines (`logs/queue-work-part1.log`, line 412).
- **How Horizon does it:** under Horizon, `ps` showed `2 × horizon:work --queue=default` and `1 × horizon:work --queue=transactional`. With `balance => auto`, Horizon gives each queue its own worker pool (at least 1 process each), rather than draining queues in listed order. The result is still good (transactional mail isn't starved), but the docs describe a different mechanism (summary item 9).

#### 3.5.2 Retry cap, with 24-hour business hours — PASS [time accelerated]

I dispatched a contact with no email at `skipRetryCount = 165` and watched each attempt (`scripts/f5_cap.php`). Each 60-minute retry delay was accelerated in Redis.

```
[08:57:29] MAX_SKIP_RETRIES=168; dispatching contact 742 with skipRetryCount=165; failed_jobs=990
[08:57:30] hop 1: processed -> requeued as delayed job with skipRetryCount=166, due in 60.0 min
[08:57:31] hop 2: processed -> requeued as delayed job with skipRetryCount=167, due in 60.0 min
[08:57:32] hop 3: processed -> requeued as delayed job with skipRetryCount=168, due in 60.0 min
[08:57:33] hop 4: job processed -> FAILED. failed_jobs 990 -> 991. queue=default
  exception: Illuminate\Queue\ManuallyFailedException: SendReviewRequest gave up on contact 742 step 1
             after 168 retries over roughly a week — still blocked on: contact_has_no_email
[08:57:36] after give-up: ready jobs for contact=0, delayed jobs for contact=0
```

- A real `failed_jobs` row was written: id 4488, uuid `e91deb26-…`.
- The ops alert was delivered through the worker: Mailpit `6FyIXFI4SeCsmex1k0XdC3`, "[ReviewEngine] A review request failed to send". The worker was restarted with `OPS_ALERT_EMAIL=ops-audit@example.com` for this, because the local `.env` leaves it blank.
- Nothing was left queued, so the job really stopped.
- Minor: "gave up after 168 retries" happens on the job carrying 168, so the job runs 169 times in total (0 through 168). This is harmless.

#### 3.5.2b Retry cap, with the **default** business hours — 🔴 FAIL (the cap never triggers)

> **Update (2026-09-16, later): fixed.** The business-hours re-delay now re-dispatches with `$this->skipRetryCount`.
>
> **Live proof:** the real `queue:work` was run with only the clock sped up (`fix-3.5.2b/virtual_clock_worker.php`, 600×) on a tenant using the real default hours (09:00–18:00 America/New_York), starting at retry 165.
> - **Before the fix:** 165 reset to 0, climbed to 9 during Thursday's hours, then reset to 0 again, and never ended.
> - **After the fix:** 165 → 166 (due Wed 18:29, closed) → re-delayed at **166** to Thu 08:59 → 167 → 168 → gave up with `failed_jobs` id 4491. Nothing was left queued, and the ops alert was delivered.
>
> **New test:** "the skip-retry count survives a closed-hours re-delay…" walks the same chain on default hours with Laravel's test clock. It fails without the fix (167→0) and passes with it.
>
> The original finding is kept below for the record.

- `TimingRule::findOrCreateDefault()` sets business hours to 09:00–18:00 America/New_York.
- In `prepare()`, the business-hours check (`SendReviewRequest.php:237`) runs **before** the skip checks.
- When it's outside business hours, `handle()` re-dispatches with `self::dispatch($this->contactId, $this->step)` (line 115). That drops `skipRetryCount`, so it goes back to 0.

Live proof. At 04:56 New York time (closed), I dispatched a contact with no email, **one retry below the cap**:

```
[08:56:50] MAX_SKIP_RETRIES=168; dispatching contact 743 with skipRetryCount=167; failed_jobs=990
[08:56:51] hop 1: processed -> requeued as delayed job with skipRetryCount=0, due in 243.0 min
```

**Effect:**
- With hourly retries and a 9-hour window, a stuck contact gets about 10 retries a day, then the counter resets to 0 overnight. It can never reach 168.
- So for every tenant on default (or any non-24h) hours, a stuck contact still retries forever, which is the exact behavior Finding 5 was meant to stop.
- The existing test (`SendReviewRequestTest` "…AT the cap gives up…") passes only because it sets business hours to 24 hours with `setTenantBusinessHoursWideOpen()`.

**Fix direction:** carry `$this->skipRetryCount` through the re-delay at line 115. Also add a test that crosses the business-hours boundary.

#### 3.5.3 🔴 NEW BUG — jobs queued before the deploy crash after it

> **Update (2026-09-16, later): fixed, and the impact below was overstated.**
> - **Narrower impact:** the missing property is only read on the skip-and-retry path (line 125 onward). A pre-fix job whose contact is fully set up **sent successfully even on the unfixed code** (evidence: `fix-3.5.3/legacy-before.txt`). Only pre-fix jobs that hit a *skip* (a prerequisite missing, or no email) crashed. They crashed instead of retrying later, so the request is lost only if the tenant later completes setup. The "every scheduled review request will fail" sentence below is wrong.
> - **Fix:** `$skipRetryCount` is now a declared, non-readonly property with a default of 0. The before/after runs through a real worker, the `queue:retry` of the dev rows, and the new regression test are in `fix-3.5.3/`.

- 951f883 added a new constructor property, `public readonly int $skipRetryCount = 0`.
- PHP's `unserialize()` doesn't run the constructor, so a job serialized by the *old* code (which has no `skipRetryCount` in its payload) comes back with that property **uninitialized**. The first read (the `Log::info` at line 125, and the retry check) throws.

**Reproduced on demand** (`scripts/f5_legacy_payload.php`):
- Took a real step-2 payload and removed `skipRetryCount`, making it byte-for-byte what the old code would have queued (`O:26:"App\Jobs\SendReviewRequest":3:{s:9:"contactId";i:692;s:4:"step";i:2;…}`).
- Pushed it to `default` and let the real worker run it:

```
08:58:15 SendReviewRequest a08b6cf5-… RUNNING / FAIL   (released, backoff 60s)
08:59:15 SendReviewRequest a08b6cf5-… RUNNING / FAIL   (released, backoff 300s) [time accelerated]
08:59:18 SendReviewRequest a08b6cf5-… RUNNING / FAIL   → failed_jobs id 4489:
  Error: Typed property App\Jobs\SendReviewRequest::$skipRetryCount must not be accessed before initialization
         in app/Jobs/SendReviewRequest.php:125
```

**Already happened locally:** the dev database has **990** `failed_jobs` rows with exactly this error, dated 2026-09-11 16:33 to 2026-09-14 20:38. These are pre-fix jobs that a dev worker ran after the change.

**Production impact (inferred, not checked on the server):**
- Step-2 and step-3 sends are queued days ahead (delays of 3 and 7 days).
- Every review request already scheduled when 951f883 was deployed will fail permanently instead of sending.
- Also, the ops alert is throttled to once per tenant per 15 minutes, so a mass failure produces few alerts.

**Fix direction:** make the property tolerate old payloads, and then re-queue the affected `failed_jobs` rows (`queue:retry`) once the fix is deployed. For example:
- a non-readonly property with a default, `public int $skipRetryCount = 0;` declared outside the constructor, or
- a `__wakeup`/`__unserialize` that fills in the default.

#### 3.5.4 Deploy doesn't restart workers — ⚠ risk, not verified on the server

- `deploy.yml` never restarts workers (no `horizon:terminate` or `queue:restart`).
- Horizon reads its supervisor config and the app code once, when it starts. An instance running from before 951f883 keeps its old supervisor, which listens on `queue => ['default']` only.
- Meanwhile the newly deployed web code pushes every account email to `transactional`, so those emails would never be processed until Horizon is restarted.
- `.claude/STAGING_VERIFICATION.md` lists `horizon:terminate` as a *manual* step. **Please check on the server** with `php artisan horizon:status` and the Horizon dashboard. Also check that `OPS_ALERT_EMAIL` is set there.

### 3.6 Finding 6 — GBP connect with credentials missing or present

| Case | Result |
|---|---|
| **Missing** (`GOOGLE_CLIENT_ID`/`SECRET` blank, as in the local `.env`), real browser, clicked "Connect Google Business Profile" | Stayed in the app at `/gbp/connect?error=gbp_not_configured`, with the inline message "Connecting Google Business Profile isn't available right now…". **Zero requests** to `accounts.google.com`. Screenshot: `f6-absent-inline-error.png` |
| **Missing**, API level | `GET /api/v1/gbp/connect` returns **503** `{"error":"gbp_not_configured",…}`. The 3 new Pest cases (blank ID, missing secret, invalid redirect URL) pass |
| **Present** (backend restarted with placeholder `GOOGLE_CLIENT_ID`/`SECRET` in its environment), real browser | Went to `accounts.google.com` with `client_id=audit-placeholder…`, the `business.manage` scope, and a `state` value, so the normal flow is unchanged. Screenshot: `f6-present-google-page.png` |
| **Present but wrong** | Google shows **"Access blocked: Authorization Error — Error 401: invalid_client"**. The raw Google error page still happens for bad credentials; the fix only covers *missing* ones (summary item 7) |

**Not testable locally:** a real consent screen, the callback, and review sync all need real Google OAuth credentials.

### 3.7 Finding 7 — checkout button, token missing or present

| Case | Result |
|---|---|
| **Missing** (`NEXT_PUBLIC_PADDLE_CLIENT_SIDE_TOKEN` blank, as in `.env.local`) | The Billing tab shows "Checkout is temporarily unavailable. Please try again later or contact support." and the button is disabled (`f7-absent-checkout-note.png`) |
| **Present** (frontend restarted with a placeholder `test_…` token) | The note is **not** shown and the button is **enabled** (`f7-present-checkout.png`). Clicking it called the real `POST /subscribe`, which, with no Paddle server keys locally, returned the app's own friendly "Checkout isn't available right now. Try again shortly." (`f7-present-after-click.png`) |

**Not testable locally:** a real Paddle checkout overlay or payment, which needs real Paddle sandbox credentials.

---

## 4. Full test suites (one pass each)

### 4.1 Backend (Pest) — `php artisan test`

**345 passed, 4 failed** (1,959 assertions, 27s). No worker was running. The Redis `default` list was empty, but `queues:transactional` held **1** leftover job from earlier activity.

- **All 4 failures:** `NotificationsSurviveRealQueueWorkerTest` ("Expected a real mail message to … found none").
- **Cause (proven):** each test takes the *first* job off the shared `transactional` list. One leftover job shifts every test onto the wrong job.

| `transactional` state before the run | Result of that test file run on its own |
|---|---|
| Empty | 2 passed, 2 **errors**: `Call to undefined function verifyUrlFor()` and `seedActiveSubscription()` (helpers defined in other test files) |
| 1 unrelated leftover job | **4 failed** |

- Earlier in this session (same code), a full run had 1 failure in `SendReviewRequestTest` "…lands a real row in failed_jobs…". That was caused by the 2,275 leaked jobs then sitting in `queues:default`: its `queue:work --once` took one of those instead of its own job. It passed once that list was cleared.

**Bottom line:** the product code passes. The two tests that cover the real-queue behavior give false failures depending on leftover Redis state and on which test files run. Give the test environment its own Redis database or prefix (e.g. `REDIS_DB=1` or `REDIS_PREFIX` in `.env.testing`), and move the shared helpers into `tests/Helpers.php`.

### 4.2 Frontend (Playwright) — `npx playwright test`

**17 passed, 3 failed** (1.5 min).

| Test | Result | Cause |
|---|---|---|
| `marketing-visual.spec.ts` tablet (768×1024) no-overflow | ✅ pass | Finding 2 fixed (this test was failing in the original audit) |
| `registration.spec.ts` mismatched password | ❌ in the full run, ✅ **3/3 when run on its own** | Hit the `/register` rate limit (5 per minute; the error was "Too many attempts"). The suite registers more than 5 accounts a minute, so this is the suite's design, not an app bug |
| `quick-add.spec.ts` "a real customer can be added…" | ❌ | The shared account from `global-setup.ts` is never email-verified, so sending stays blocked |
| `template-compliance.spec.ts` "unreachable compliance checker…" | ❌ | Same cause: the first alert on the page is the "Verify your email…" banner |

The original audit called the last two failures "a downstream symptom of Finding 1". That isn't correct: Finding 1 is now fixed and they still fail, because the test setup never verifies the account. They need `global-setup.ts` to verify the shared account, for example by reading the email from Mailpit or marking it verified through a test-only step.

Side effect: running the suite rewrites `frontend/e2e/screenshots/marketing-*.png`, which now show as modified in git.

---

## 5. What I'm not fully sure of

- **The production server was not examined.** The deploy/worker-restart risk, the likelihood that the old-job crash hit real tenants, and whether `OPS_ALERT_EMAIL` is set there are all inferred from the repo, not observed.
- **GBP-revoked** was triggered with a stubbed Google response, not a real revocation. The queuing and delivery were real.
- **Finding 5 timing** ran on one local machine with one `queue:work` process, or Horizon's local config (up to 3 processes). The ordering result is sound; absolute timings under production load will differ.
- **[time accelerated]** steps replaced the real 60-minute retry delays and 60s/300s backoffs by moving delayed jobs' due time forward in Redis. The code paths were real; the waiting was not.
- **Real Google OAuth, a real Paddle checkout, and a real AI compliance verdict** remain untestable here without those credentials, same as the original audit.
- **Quick observation (low confidence):** `VerifyEmailAddress`'s constructor also changed shape, so any pre-fix `VerifyEmailAddress` jobs still queued would also fail to unserialize. Those were already silently dropped before the fix, so this isn't a new loss; I did not reproduce it.

---

## 6. State changes made during this audit (for cleanup)

- **Postgres:** the Homebrew service wasn't starting because of a stale `postmaster.pid` from 2026-09-09 (the PID in it now belongs to Slack). It was renamed to `/usr/local/var/postgresql@16/postmaster.pid.stale-2026-09-09`, and Postgres was started.
- **Redis:**
  - 2,275 + 15 leaked test jobs were moved to `audit-aside:2026-09-16:queues:default` and `…:default:delayed`. **Nothing was deleted.**
  - The ~6,000 delayed retry jobs this audit created were removed.
  - `queues:transactional` currently holds 5 real verification jobs from the e2e run, and they will be sent when a worker next runs. They will also make `NotificationsSurviveRealQueueWorkerTest` fail until the queue is empty (§4.1).
- **Dev database:** the audit created these records (all `@example.com` or `.test` addresses):
  - Tenants for `reverify-f1-1789548777227@example.com` and `playwright-reverify-resend-1789548798700@example.com`, plus the e2e suite's own `playwright-*` tenants.
  - 52 audit contacts (IDs 692–743, names "Audit …").
  - One GBP connection (status `revoked`).
  - One subscription (`sub_audit…`, id 4).
  - One timing rule set to 24 hours.
  - `failed_jobs` rows 4488 (retry cap) and 4489 (old-payload reproduction).
- **Mailpit** is still running on `127.0.0.1:8025` with the evidence emails in memory. They are lost when Mailpit stops; the screenshots are saved.
- **Code:** no application code was changed by this audit. The Finding 6/7 changes predate it and are still uncommitted.
