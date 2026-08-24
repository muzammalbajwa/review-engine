# STAGING VERIFICATION — runbook for the first real-infrastructure pass

Exact commands for the five checks below, ready to run the moment real
staging credentials and deploy access exist. Every command here targets
staging specifically (SSH'd into the staging box, or run locally against
`--env=staging` where noted) — nothing here is a substitute for the local
`composer test`/Playwright passes already done; it's the same rigor,
proven against real infrastructure instead of Mailpit/local Postgres/no
Lemon Squeezy key.

## 0. Before any of the five checks

- [ ] `.env` on the staging box built from `backend/.env.staging.example`,
      every value filled in for real. `APP_DEBUG=false`. `APP_ENV=staging`
      (not `production` — this is what `config/horizon.php`'s
      `environments` map now keys off; see that file's own comment for
      why this matters more than it looks like it should).
- [ ] `frontend/.env.staging.example` copied to `.env.production` on
      whatever serves the staging frontend build (Next.js's own
      convention — it reads `.env.production` for any non-development
      build, staging included).
- [ ] `FRONTEND_URL` (backend) and `NEXT_PUBLIC_APP_URL` (frontend) match
      exactly — scheme, host, port. This is CORS, in full; there's no
      other setting (`config/cors.php` reads `FRONTEND_URL` directly, no
      wildcards).
- [ ] Migrations run via the owner connection:
      `php artisan migrate --database=pgsql_owner --force`
- [ ] Redis reachable, Horizon actually running with real workers —
      don't trust `horizon:started` output alone:
      ```
      php artisan horizon:status
      # AND separately, confirm real supervisor state directly:
      php artisan tinker --execute='print_r(app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all());'
      ```
      A live 2026-08-07 audit found `php artisan horizon` prints "Horizon
      started successfully" **unconditionally**, even when zero
      supervisors were actually provisioned (an unmatched `APP_ENV` in
      `config/horizon.php`'s `environments` map silently deploys nothing —
      fixed this session with an explicit `staging` entry + a `*`
      wildcard backstop, but confirm the fix actually took effect on the
      real box before trusting anything below).
- [ ] The OS-level cron entry Laravel's scheduler depends on is actually
      installed on the staging box — `queue:check-heartbeat` (item 5,
      below) and `drip:release-pending` (item 2) only fire on their own
      schedule via `schedule:run`, which does nothing without this:
      ```
      * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
      ```
      Confirm it's there (`crontab -l`) before assuming either scheduled
      command will ever fire on its own.

---

## 1. Cross-tenant isolation gate, including personal_access_tokens

Two options. Prefer the second — it's the same technique that originally
found the `personal_access_tokens` leak (live probe against real data,
full visibility into what's actually happening), not a mocked run.

**Option A — the real Pest suite, against staging's own schema.** Only
if staging has a database you're comfortable running `DatabaseTransactions`-wrapped
tests against (they roll back, but don't point this at a database
carrying real customer data):
```
php artisan test --filter=CrossTenantIsolationTest --env=staging
```
All 8 tests in that file must pass, including the three
`personal_access_tokens`-specific ones added this session.

**Option B — live probe, SSH'd into staging, mirrors exactly how the
original leak was found and how the fix was proven:**
```
php artisan tinker --execute='
use App\Models\Tenant; use App\Models\User; use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;

function seed($name) {
    return DB::transaction(function () use ($name) {
        $tenantId = (string) Str::uuid();
        DB::statement("SELECT set_config(?, ?, true)", ["app.current_tenant_id", $tenantId]);
        $t = new Tenant(["name" => $name, "type" => "customer"]);
        $t->id = $tenantId; $t->save();
        $u = new User(["name" => "$name Owner", "email" => Str::lower(Str::slug($name))."-".Str::random(6)."@example.com", "password" => bcrypt("x")]);
        $u->tenant_id = $tenantId; $u->role = "owner"; $u->save();
        $token = $u->createToken("webhook-api-key", ["contacts:create"]);
        return [$t, $u, $token->accessToken];
    });
}

[$tenantA, $userA, $tokenA] = seed("Staging Verify A");
[$tenantB, $userB, $tokenB] = seed("Staging Verify B");

DB::transaction(function () use ($tenantA, $userA, $tokenA, $tokenB) {
    DB::statement("SELECT set_config(?, ?, true)", ["app.current_tenant_id", $tenantA->id]);
    app(CurrentTenant::class)->set($tenantA->id);

    $rows = DB::table("personal_access_tokens")->get();
    echo "Rows visible to Tenant A (raw, unfiltered query): " . $rows->count() . "\n";
    echo "Contains Tenant B token? " . ($rows->contains("id", $tokenB->id) ? "YES -- REGRESSION" : "no -- correct") . "\n";

    app(CurrentTenant::class)->clear();
});
'
```
**Expected**: exactly 1 row (Tenant A's own), never Tenant B's. Anything
else is a real regression — stop and do not proceed to items 2–5 until
this is understood.

---

## 2. Send pipeline — a real email through Resend/Postmark, not Mailpit

```
# SSH'd into staging
php artisan tinker
```
```php
use App\Models\{Tenant, User, Campaign, Contact, SenderIdentity, GbpConnection, TimingRule};
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$tenantId = (string) Str::uuid();
DB::transaction(function () use ($tenantId) {
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
    app(CurrentTenant::class)->set($tenantId);

    $t = new Tenant(['name' => 'Staging Send Verify', 'type' => 'customer']);
    $t->id = $tenantId; $t->save();

    $u = new User(['name' => 'Staging Verify', 'email' => 'staging-verify-'.Str::random(6).'@example.com', 'password' => bcrypt('x')]);
    $u->tenant_id = $tenantId; $u->role = 'owner'; $u->save();

    // A REAL email address you can actually check — this is the whole point.
    $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
    $campaign->tenant_id = $tenantId; $campaign->save();

    $contact = new Contact(['campaign_id' => $campaign->id, 'name' => 'Staging Verify Contact', 'email' => 'YOUR-REAL-INBOX@example.com', 'status' => 'pending']);
    $contact->tenant_id = $tenantId; $contact->consent_at = now(); $contact->source = 'quick_add'; $contact->save();

    $sender = new SenderIdentity(['from_name' => 'Staging Verify', 'from_email' => 'YOUR-VERIFIED-SENDER-DOMAIN@example.com', 'verified' => true]);
    $sender->tenant_id = $tenantId; $sender->save();

    // Fake token, real review_link — GBP OAuth itself is item 4, separate.
    $gbp = new GbpConnection(['oauth_token' => 'placeholder', 'refresh_token' => 'placeholder', 'token_expires_at' => now()->addYear(), 'location_id' => 'locations/staging-verify', 'review_link' => 'https://g.page/r/staging-verify/review', 'status' => 'connected']);
    $gbp->tenant_id = $tenantId; $gbp->save();

    $timing = new TimingRule(['delay_minutes_step2' => 4320, 'delay_minutes_step3' => 10080, 'business_hours_start' => '00:00:00', 'business_hours_end' => '23:59:59', 'timezone' => 'UTC']);
    $timing->tenant_id = $tenantId; $timing->save();

    app(CurrentTenant::class)->clear();
});

echo $tenantId;
```
Then, still on staging:
```
php artisan drip:release-pending -v
```
**Real evidence to capture**:
- The command's own output line (`Released N contact(s)...`)
- The `messages` row for this contact: `status = 'sent'`, real `sent_at`,
  and — this is the part local testing with Mailpit couldn't show —
  `provider_id` populated with Resend/Postmark's own real message ID
  (`SendReviewRequest::handle()` already stores
  `$sentMessage?->getMessageId()` there)
- **The actual email, in the real inbox** used above — subject, sender,
  body, the tracked click link resolving correctly
- Resend/Postmark's own dashboard showing the send logged, matching the
  `provider_id` from the DB row

---

## 3. Lemon Squeezy webhook, real test-mode signed event

Lemon Squeezy has no CLI equivalent to `stripe listen` — the real,
signed-webhook path is: complete a real test-mode checkout (dummy
customer details + one of their test card numbers, e.g. `4242 4242 4242
4242`, from `https://[STORE].lemonsqueezy.com/checkout/buy/[VARIANT_ID]`),
which fires real webhooks to whatever URL is configured, the same as a
live purchase would.

1. Dashboard → Settings → Webhooks → create one pointed at
   `https://YOUR-STAGING-DOMAIN/api/v1/lemon-squeezy/webhook`, subscribed
   to at least `subscription_created`/`subscription_updated`/
   `subscription_cancelled`, signing secret set to staging's
   `LEMON_SQUEEZY_SIGNING_SECRET`.
2. Complete a real test-mode checkout for either variant (test mode is the
   store's default until it's activated for real sales — confirm the test
   mode toggle is on first).
3. Dashboard → Settings → Webhooks → your staging endpoint → recent
   events log shows what was actually sent and staging's response code.

For re-triggering a specific event on that same test subscription without
a second full checkout: Dashboard → the subscription's own page →
"Simulate webhook events" → pick `subscription_cancelled` (or any other
subscription lifecycle event) → send. This is the closest equivalent to
`stripe trigger`.

**Real evidence to capture**:
- The webhook log entry showing a `200` response back from staging (not
  400/403 — `VerifyWebhookSignature` is applied unconditionally as route
  middleware in `routes/api.php`, specifically so a misconfigured
  `LEMON_SQUEEZY_SIGNING_SECRET` fails closed rather than silently
  accepting an unverified payload — a 403 here most likely means that
  secret doesn't match what's registered)
- The corresponding `lemon_squeezy_subscriptions` row created/updated in
  the staging database with the real Lemon Squeezy IDs from the event,
  AND the owning tenant's `status`/`billing_interval` reflecting it —
  confirms `LemonSqueezyWebhookController`'s tenant-context resolution
  (from `meta.custom_data.tenant_id`) actually worked against a real
  payload, not just a locally-constructed test one.

---

## 4. GBP OAuth, Google's real consent screen

No way to script this one — it's a real human clicking through a real
Google account.

1. In a real browser, log into the staging frontend as a real (or
   throwaway) tenant.
2. Navigate to Settings → Google Business Profile → **Connect Google
   Business Profile**.
3. Confirm the redirect lands on `accounts.google.com` with the real
   staging `GOOGLE_CLIENT_ID` and a `redirect_uri` that **exactly**
   matches what's registered in the Google Cloud Console for this OAuth
   client (`https://YOUR-STAGING-DOMAIN/api/v1/gbp/callback`) — a
   mismatch here is Google's own rejection, not this app's.
4. Complete the consent screen with a real Google account that manages a
   real Business Profile location.
5. Confirm the callback redirect lands back on the staging frontend
   without error.

**Real evidence to capture**:
- The `gbp_connections` row: `status = 'connected'`, a real
  `location_id`, a real `review_link`, and `oauth_token`/`refresh_token`
  populated (check via `DB::table('gbp_connections')->...` under the
  right tenant context — never log the decrypted values themselves,
  SECURITY.md #5: encrypted at rest)
- `php artisan gbp:sync-reviews` run once by hand afterward, confirmed to
  fetch real reviews for that real location without a
  `GbpConnectionRevokedException`

---

## 5. Worker heartbeat alert, firing for real

```
# SSH'd into staging
php artisan horizon:terminate
```
Wait at least one full minute (the heartbeat check's own schedule —
`Schedule::command('queue:check-heartbeat')->everyMinute()`) for cron to
pick it up on its own. To force it immediately instead of waiting:
```
php artisan queue:check-heartbeat
```
**Real evidence to capture**:
- The real email landing in `OPS_ALERT_EMAIL`'s actual inbox —
  `WorkerHeartbeatDown` notification, subject and body naming the real
  reason ("Horizon is not running" or "paused")
- The corresponding `Log::critical(...)` line in staging's own log
  output (`storage/logs/laravel.log` or wherever `LOG_CHANNEL=stack`
  routes it)

**Then restart Horizon** — this check deliberately breaks production
send capability while it runs:
```
nohup php artisan horizon > /dev/null 2>&1 &
php artisan horizon:status   # confirm it says running, then re-verify
                              # via the MasterSupervisorRepository check
                              # in item 0 — not just the status line.
```

---

## After all five

Re-run `php artisan horizon:status` one final time and confirm real
supervisors are back up — leaving staging's queue silently drained after
a verification pass would be exactly the kind of "confident success, no
real work happening" failure this whole runbook exists to catch.
