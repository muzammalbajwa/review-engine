<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The 7-day, no-card free trial's start (.claude/BILLING.md: "do NOT
 * create a Lemon Squeezy Customer/Subscription at trial start... track the
 * trial entirely in our own database"). Nothing here ever supplies a
 * payment_method — the whole point of this endpoint's existence, separate
 * from POST /subscribe, is that it can't.
 *
 * 'standard' is the only plan key that exists (.claude/BILLING.md's
 * single-plan pricing change replaced Starter/Growth/Pro) — see
 * config/plans.php's own docblock for why it's still a keyed plan object
 * rather than a bare value.
 */
test('starting a trial requires authentication', function () {
    $this->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])->assertUnauthorized();
});

test('starting a trial requires a valid, whitelisted plan key', function () {
    [$token] = seedCustomerAccount('Start Trial Validation');

    $missing = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', []);
    $missing->assertStatus(422);
    expect($missing->json('fields.plan'))->not->toBeNull();

    $bogus = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'enterprise-unlimited']);
    $bogus->assertStatus(422);
    expect($bogus->json('fields.plan'))->not->toBeNull();

    // The old three-tier keys are gone — a client that hasn't caught up to
    // the pricing change gets the same clean 422, not a stale success.
    $stale = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'growth']);
    $stale->assertStatus(422);
    expect($stale->json('fields.plan'))->not->toBeNull();
});

test('starting a trial sets trial_started_at, trial_ends_at (+7 days), status=trialing and plan — no card, no Lemon Squeezy call', function () {
    [$token, $tenantId] = seedCustomerAccount('Start Trial Happy Path');

    $before = Carbon::now();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard']);

    $response->assertCreated();
    expect($response->json('data.tenant_status'))->toBe('trialing');
    expect($response->json('data.plan'))->toBe('standard');
    expect($response->json('data.trial_ends_at'))->not->toBeNull();

    $tenant = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Tenant::find($tenantId);
    });

    expect($tenant->status)->toBe('trialing');
    expect($tenant->plan)->toBe('standard');
    // No billing interval either — that's chosen at real conversion, never
    // at trial start (.claude/BILLING.md's "Conversion" section).
    expect($tenant->billing_interval)->toBeNull();
    expect($tenant->trial_started_at)->not->toBeNull();
    expect($tenant->trial_started_at->diffInSeconds($before))->toBeLessThan(5);
    // Exactly 7 days after trial_started_at, not "now" at assertion time.
    expect($tenant->trial_ends_at->diffInSeconds($tenant->trial_started_at->copy()->addDays(7)))->toBeLessThan(2);

    // No Lemon Squeezy customer/subscription exists — the entire point of
    // this decision. The `customer` relation (Billable::customer(), a
    // MorphOne on lemon_squeezy_customers) is never populated by this
    // endpoint.
    $hasCustomer = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return User::query()->first()->customer !== null;
    });
    expect($hasCustomer)->toBeFalse();
});

test('a tenant who already picked a plan cannot start a trial again', function () {
    [$token] = seedCustomerAccount('Start Trial Twice');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard']);

    $second->assertStatus(422);
    expect($second->json('error'))->toBe('trial_already_started');

    // The original plan/trial window is untouched by the rejected retry —
    // the meaningful assertion here is trial_ends_at not moving, since
    // there's only one plan key now to prove "unchanged" with.
    $before = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/onboarding/status')
        ->json('data.trial_ends_at');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard']);

    $after = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/onboarding/status')
        ->json('data.trial_ends_at');

    expect($after)->toBe($before);
});

test('starting a trial is reflected immediately by onboarding status', function () {
    [$token] = seedCustomerAccount('Start Trial Onboarding Status');

    $before = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');
    expect($before->json('data.subscribed'))->toBeFalse();
    expect($before->json('data.tenant_status'))->toBe('pending');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $after = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');
    expect($after->json('data.subscribed'))->toBeTrue();
    expect($after->json('data.tenant_status'))->toBe('trialing');
});
