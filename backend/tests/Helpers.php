<?php

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\GbpConnection;
use App\Models\Message;
use App\Models\SenderIdentity;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared across Feature/Admin tests (and anything else that needs an
 * admin/customer account or a fake Horizon supervisor) — a global PHP
 * function can only be declared once per process, so these moved here out
 * of the single test file that originally owned them the moment a second
 * file needed the same helper.
 */
function seedAdminAccount(string $label): array
{
    $tenantId = (string) Str::uuid();
    $email = strtolower(str_replace(' ', '', $label)).'-admin-'.uniqid().'@example.com';
    $password = 'correct-horse-battery-staple';

    DB::transaction(function () use ($tenantId, $label, $email, $password) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => "{$label} Co", 'type' => 'admin']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();
    });

    $login = test()->postJson('/api/v1/login', ['email' => $email, 'password' => $password])
        ->assertOk();

    expect($login->json('data.user.is_admin'))->toBeTrue();

    return [$login->json('data.token'), $tenantId];
}

/**
 * Verified by default (see markEmailVerified() below) — the "add email
 * verification" decision doc adds a real, enforced gate
 * (Tenant::sendingBlockedReason()'s 'email_unverified' case), and the
 * overwhelming majority of call sites across this suite exist to test
 * something else entirely and simply need "a real account that can send"
 * as setup, same reasoning makeVerifiedSenderIdentity() below is verified
 * by default rather than making every caller opt in. Tests that actually
 * exercise the unverified-blocking behavior itself use
 * seedUnverifiedCustomerAccount() instead
 * (tests/Feature/Auth/EmailVerificationTest.php).
 */
function seedCustomerAccount(string $label): array
{
    [$token, $tenantId, $userId] = seedUnverifiedCustomerAccount($label);

    markEmailVerified($userId, $tenantId);

    return [$token, $tenantId];
}

/**
 * The real registration flow, deliberately left unverified — for
 * EmailVerificationTest.php's own tests of the gate itself. Returns the
 * user id too (seedCustomerAccount's own callers never needed it, hence
 * the 2-element return there) since a caller here needs it to build a
 * real signed verification link the same way
 * AuthController::verifyEmail()'s own route expects.
 */
function seedUnverifiedCustomerAccount(string $label): array
{
    $response = test()->postJson('/api/v1/register', [
        'name' => "{$label} Owner",
        'business_name' => "{$label} Co",
        'email' => strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    return [$response->json('data.token'), $response->json('data.tenant.id'), $response->json('data.user.id')];
}

/**
 * Direct DB write, not a real click-the-link flow (EmailVerificationTest.php
 * covers that end to end) — same "don't route every test through the full
 * flow when the flow itself isn't what's under test" convention
 * seedTeamMember()/expireTrialDirect() already use elsewhere in this suite.
 */
function markEmailVerified(int $userId, string $tenantId): void
{
    DB::transaction(function () use ($userId, $tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        User::query()->find($userId)?->forceFill(['email_verified_at' => now()])->save();
    });
}

/**
 * Directly creates a role='member' user on an existing tenant, bypassing
 * the invite-email flow — the invite/accept flow itself is exercised for
 * real by tests/Feature/Team/TeamInviteTest.php; every other test that
 * just needs "a member with these permissions already on this tenant" (to
 * exercise RequirePermission/EnsureTenantOwner) uses this instead, same
 * "don't route every test through the full flow when the flow itself
 * isn't what's under test" convention as makeVerifiedSenderIdentity above.
 */
function seedTeamMember(string $tenantId, string $label, array $permissions = []): array
{
    $email = strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com';
    $password = 'correct-horse-battery-staple';

    $userId = DB::transaction(function () use ($tenantId, $label, $email, $password, $permissions) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $user = new User([
            'name' => $label,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'member';
        $user->permissions = $permissions;
        $user->save();

        return $user->id;
    });

    $login = test()->postJson('/api/v1/login', ['email' => $email, 'password' => $password])->assertOk();

    return [$login->json('data.token'), $userId];
}

function auditLogsForAction(string $action): Collection
{
    return DB::transaction(function () use ($action) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return AuditLog::withoutGlobalScopes()->where('action', $action)->get();
    });
}

/**
 * personal_access_tokens now carries its own RLS policy
 * (2026_08_06_133207_add_tenant_id_and_rls_to_personal_access_tokens_table.php)
 * — a raw PersonalAccessToken::findToken() call in test body code (not
 * behind any 'tenant'-middleware request) has no tenant context active,
 * same is_admin bypass pattern as auditLogsForAction() above, scoped to
 * this one lookup for test-assertion purposes only.
 */
function findTokenAsAdmin(string $plaintext): ?PersonalAccessToken
{
    return DB::transaction(function () use ($plaintext) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return PersonalAccessToken::findToken($plaintext);
    });
}

/**
 * Find-then-mutate has to happen inside the same bypass-active
 * transaction as findTokenAsAdmin() above — the transaction commits (and
 * app.is_admin reverts) as soon as the closure returns, so a separate
 * ->save() call afterward would run with no tenant context active and
 * silently affect zero rows under the normal tenant_isolation policy.
 */
function expireTokenNow(string $plaintext): void
{
    DB::transaction(function () use ($plaintext) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        PersonalAccessToken::findToken($plaintext)
            ?->forceFill(['expires_at' => now()->subMinute()])
            ->save();
    });
}

/**
 * .claude tests/Feature/Queue/WorkerHeartbeatTest.php: no real Redis-backed
 * Horizon master exists in this suite — MasterSupervisorRepository is faked
 * with a plain in-memory double so "Horizon is/isn't running" is fully
 * controlled per test.
 */
class FakeMasterSupervisorRepository implements MasterSupervisorRepository
{
    public function __construct(private readonly array $masters = []) {}

    public function names()
    {
        return array_keys($this->masters);
    }

    public function all()
    {
        return $this->masters;
    }

    public function find($name)
    {
        return $this->masters[$name] ?? null;
    }

    public function get(array $names)
    {
        return array_intersect_key($this->masters, array_flip($names));
    }

    public function update($master) {}

    public function forget($name) {}

    public function flushExpired() {}
}

function bindMasterSupervisors(array $masters): void
{
    app()->instance(MasterSupervisorRepository::class, new FakeMasterSupervisorRepository($masters));
}

/**
 * Shared by tests/Feature/Queue/SendReviewRequestTest.php and
 * ReleasePendingContactsTest.php — SendReviewRequest needs a verified
 * sender identity to have anywhere to send *from*, same requirement the
 * job itself enforces before attempting a send.
 */
function makeVerifiedSenderIdentity(string $tenantId, string $fromEmail = 'hello@example.com', string $fromName = 'Test Business'): void
{
    DB::transaction(function () use ($tenantId, $fromEmail, $fromName) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $identity = new SenderIdentity(['from_name' => $fromName, 'from_email' => $fromEmail, 'verified' => true]);
        $identity->tenant_id = $tenantId;
        $identity->save();
    });
}

/**
 * A connected GBP connection with a review_link — SendReviewRequest's
 * only source for the one link every contact gets (.claude/CLAUDE.md
 * golden rule #3), so it refuses to send without one.
 */
function makeConnectedGbpConnection(string $tenantId, string $reviewLink = 'https://g.page/r/test-location/review'): void
{
    DB::transaction(function () use ($tenantId, $reviewLink) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $connection = new GbpConnection([
            'oauth_token' => 'fake-oauth-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/test-123',
            'review_link' => $reviewLink,
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantId;
        $connection->save();
    });
}

/**
 * A Paddle Customer row already existing for this tenant's owner —
 * mirrors exactly what Billable::checkout()'s createAsCustomer() call
 * does synchronously at real checkout time (SubscriptionController::
 * subscribe()), before Paddle ever sends a webhook. PaddleWebhookController
 * resolves tenant_id by looking this row up (paddle_id -> tenant_id),
 * never from custom_data alone — see that controller's docblock — so
 * every webhook-handling test needs one of these to exist first, the
 * same way a real webhook could never arrive for a tenant that hadn't
 * already started a real checkout.
 */
function seedPaddleCustomer(string $tenantId, int $userId, string $paddleCustomerId): void
{
    DB::transaction(function () use ($tenantId, $userId, $paddleCustomerId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $user = User::query()->find($userId);

        // BelongsToTenant's creating-hook auto-fill reads
        // app(CurrentTenant::class)->id() — the PHP-side singleton, not
        // the Postgres session var set above — which this helper never
        // sets. Explicit assignment before save() (same "explicit always
        // wins" rule the trait documents) is also the established
        // convention every other tenant-row test helper in this file
        // uses (makeVerifiedSenderIdentity, makeConnectedGbpConnection),
        // rather than relying on a process-wide singleton that could
        // just as easily be leaking a *different* tenant's id left over
        // from an earlier test in the same PHP process.
        $customer = $user->customer()->make([
            'paddle_id' => $paddleCustomerId,
            'name' => $user->name,
            'email' => $user->email,
        ]);
        $customer->tenant_id = $tenantId;
        $customer->save();
    });
}

/**
 * Paddle's real webhook signing scheme
 * (developer.paddle.com/webhook-reference/verifying-webhooks, verified
 * directly against the installed Laravel\Paddle\Http\Middleware\
 * VerifyWebhookSignature source, not assumed): `ts=<unix>;h1=<hex>` in
 * the Paddle-Signature header, hash = HMAC-SHA256("{$ts}:{$rawBody}",
 * $secret). $timestamp defaults to now() — the middleware's own
 * maximumVariance (5 seconds) rejects anything signed further in the
 * past than that, so a test that wants to prove the freshness check
 * itself must pass an explicitly stale $timestamp.
 */
function paddleSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $hash = hash_hmac('sha256', "{$timestamp}:{$payload}", $secret);

    return "ts={$timestamp};h1={$hash}";
}

/**
 * POSTs a real, correctly-signed Paddle webhook request — every
 * PaddleWebhookController test goes through this rather than calling the
 * controller directly, so VerifyWebhookSignature (route middleware,
 * applied unconditionally in routes/api.php) is exercised for real on
 * every one of them, not just the dedicated signature tests.
 */
function postSignedPaddleWebhook(array $payload, ?string $secret = null): \Illuminate\Testing\TestResponse
{
    $secret ??= config('cashier.webhook_secret');
    $body = json_encode($payload);
    $signature = paddleSignatureHeader($body, $secret);

    return test()->call('POST', '/api/v1/paddle/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PADDLE_SIGNATURE' => $signature,
    ], $body);
}

function ownerIdFor(string $tenantId): int
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->where('tenant_id', $tenantId)->where('role', 'owner')->value('id');
    });
}

/**
 * A real subscription.created payload — used by any test that needs a
 * tenant to already have an active Paddle subscription as setup (the
 * auto-renew toggle, renewal reminders), not just PaddleWebhookStatusTest
 * itself, hence living here rather than in that one file (this file's own
 * docblock: moved out the moment a second file needs the same helper).
 */
function subscriptionCreatedPayload(string $customerId, string $subscriptionId, string $tenantId, string $priceId): array
{
    return [
        'event_id' => 'evt_'.Str::random(10),
        'event_type' => 'subscription.created',
        'data' => [
            'id' => $subscriptionId,
            'customer_id' => $customerId,
            'status' => 'active',
            'next_billed_at' => now()->addMonth()->toIso8601String(),
            'custom_data' => ['subscription_type' => 'default', 'tenant_id' => $tenantId],
            'items' => [
                ['price' => ['id' => $priceId, 'product_id' => 'pro_test'], 'status' => 'active', 'quantity' => 1],
            ],
        ],
    ];
}

/**
 * Convenience wrapper most tests actually want: seeds the Paddle Customer
 * row (mirrors createAsCustomer() at real checkout time) and posts a real,
 * signed subscription.created webhook through the full controller/Cashier
 * dispatch — leaves the tenant with a genuinely active Subscription row,
 * the same state a real converted tenant would be in. Returns the Paddle
 * subscription id for the caller's own follow-up webhooks/assertions.
 */
function activatePaddleSubscriptionForTenant(string $tenantId, int $ownerId, ?string $priceId = null): string
{
    $customerId = 'ctm_'.Str::random(14);
    $subscriptionId = 'sub_'.Str::random(14);
    $priceId ??= config('plans.standard.intervals.monthly.price');

    seedPaddleCustomer($tenantId, $ownerId, $customerId);

    postSignedPaddleWebhook(
        subscriptionCreatedPayload($customerId, $subscriptionId, $tenantId, $priceId)
    )->assertOk();

    return $subscriptionId;
}

/**
 * A representative slice of a tenant's real, non-billing product data —
 * one campaign, three contacts (one already sent to, two pending), one
 * template, one message. Originally TrialExpiryDataIntegrityTest.php's
 * own helper (moved here — a second file now needs the identical
 * standard for the Paddle checkout/past_due/cancel-at-period-end
 * lifecycle, same "no billing transition ever touches product data" bar
 * as the original trial-expiry work).
 */
function seedTrialEraData(string $tenantId): array
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        $contacts = collect(range(1, 3))->map(function (int $i) use ($tenantId, $campaign) {
            $contact = new Contact([
                'campaign_id' => $campaign->id,
                'name' => "Trial Era Contact {$i}",
                'phone' => "555-010{$i}",
                'email' => "trial-era-{$i}@example.com",
                'status' => $i === 1 ? 'sent' : 'pending',
                'consent_at' => now(),
            ]);
            $contact->tenant_id = $tenantId;
            $contact->save();

            return $contact;
        });

        $template = new Template([
            'campaign_id' => $campaign->id,
            'step' => 1,
            'body' => 'Thanks for choosing us! Please leave us a review: {{review_link}}',
            'compliance_status' => 'pass',
        ]);
        $template->tenant_id = $tenantId;
        $template->save();

        $message = new Message([
            'contact_id' => $contacts->first()->id,
            'step' => 1,
            'status' => 'sent',
            'sent_at' => now(),
            'provider_id' => 'provider-msg-'.uniqid(),
        ]);
        $message->tenant_id = $tenantId;
        $message->save();

        return [
            'campaign' => $campaign,
            'contacts' => $contacts,
            'template' => $template,
            'message' => $message,
        ];
    });
}

/**
 * Full-table snapshot, not a targeted re-fetch of the ids we already
 * know about — a snapshot keyed only on those same ids would be blind to
 * exactly the kind of bug this test exists to catch (an accidental
 * cascade delete, a stray ->delete() on the whole tenant's rows).
 */
function tenantDataSnapshot(string $tenantId): array
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return [
            'campaigns' => Campaign::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->get()->toArray(),
            'contacts' => Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->get()->toArray(),
            'templates' => Template::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->get()->toArray(),
            'messages' => Message::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->get()->toArray(),
        ];
    });
}
