<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * .claude/API.md: "Admin: GET /admin/tenants, GET /admin/tenants/{id}/*
 * (logged, admin only)". .claude/DATABASE.md: "EVERY admin cross-tenant
 * read writes an audit_log row." This had no test coverage at all before
 * this file — the Phase 1 gate's "cross-tenant isolation" tests cover
 * regular tenant tables, not this separate, RLS-bypassing admin path, so
 * none of its behavior (the 403 for non-admins, the audit logging, the
 * actual cross-tenant read) was previously verified.
 *
 * seedAdminAccount/seedCustomerAccount/auditLogsForAction live in
 * tests/Helpers.php — shared with the other Feature/Admin test files.
 */
test('listing tenants requires authentication', function () {
    $this->getJson('/api/v1/admin/tenants')->assertUnauthorized();
});

test('a non-admin tenant cannot list tenants', function () {
    [$token] = seedCustomerAccount('Non Admin List');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants')
        ->assertForbidden();
});

test('a non-admin tenant cannot view another tenant either', function () {
    [$token] = seedCustomerAccount('Non Admin Show');
    [, $otherTenantId] = seedCustomerAccount('Non Admin Show Target');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/admin/tenants/{$otherTenantId}")
        ->assertForbidden();
});

/**
 * TenantPolicy::viewAny() only ever checks $user->isAdmin() — it never
 * looks at which tenant id was actually requested (see that policy's own
 * docblock: no view($user, $tenant) method exists at all). That means a
 * non-admin owner hitting the admin drill-in route with THEIR OWN tenant
 * id should be just as forbidden as hitting it for a stranger's — a
 * distinct, real risk from "another tenant's data leaks" (a tenant
 * self-service route mistakenly reachable through the admin path would
 * bypass the read-only, audited-god-view contract even though the data
 * itself is theirs to see elsewhere). Same for /admin/tenants — a
 * customer owner gets no more through the admin *list* than through the
 * admin *show* route, payment_logs included in both.
 */
test('a non-admin tenant owner cannot reach the customer listing or their own payment history via the admin routes', function () {
    [$token, $ownTenantId] = seedCustomerAccount('Non Admin Own Tenant');
    $ownerId = ownerIdFor($ownTenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($ownTenantId, $ownerId);
    $customerId = DB::transaction(function () use ($ownTenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $ownTenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $ownTenantId)->value('paddle_id');
    });
    // A real payment_logs row exists for this tenant — proves the 403
    // below isn't just "no data to leak," it's the route itself refusing
    // a non-admin regardless of what it would have returned.
    postSignedPaddleWebhook(
        transactionCompletedPayload($customerId, $subscriptionId, $ownTenantId)
    )->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/admin/tenants/{$ownTenantId}")
        ->assertForbidden();

    // Neither attempt is an audited admin read — a 403 from Gate::authorize
    // never reaches AdminAuditLogger::record() at all, so it must not
    // write an audit_logs row either (an unauthorized attempt is not a
    // legitimate cross-tenant read to log as one).
    expect(auditLogsForAction('admin.tenants.list')->where('target', 'all')->isEmpty())->toBeTrue();
    expect(auditLogsForAction('admin.tenants.view')->where('target', $ownTenantId)->isEmpty())->toBeTrue();
});

test('an admin can list every tenant across the system, and the read is audit logged', function () {
    [$adminToken, $adminTenantId] = seedAdminAccount('List All');
    [, $customerATenantId] = seedCustomerAccount('List All Customer A');
    [, $customerBTenantId] = seedCustomerAccount('List All Customer B');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($adminTenantId, $customerATenantId, $customerBTenantId);

    $logs = auditLogsForAction('admin.tenants.list');
    expect($logs->where('target', 'all')->isNotEmpty())->toBeTrue();
});

test('an admin can view a single tenant by id, and the read is audit logged', function () {
    [$adminToken] = seedAdminAccount('View One');
    [, $customerTenantId] = seedCustomerAccount('View One Target');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson("/api/v1/admin/tenants/{$customerTenantId}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($customerTenantId);
    expect($response->json('data.name'))->toBe('View One Target Co');

    $logs = auditLogsForAction('admin.tenants.view');
    expect($logs->where('target', $customerTenantId)->isNotEmpty())->toBeTrue();
});

test('viewing a non-existent tenant id returns a clean 404, not a raw SQL error', function () {
    [$adminToken] = seedAdminAccount('View Missing');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants/'.Str::uuid());

    $response->assertNotFound();
    expect($response->json('error'))->toBe('not_found');
});

test('a malformed tenant id in the URL does not reach the database', function () {
    [$adminToken] = seedAdminAccount('View Malformed');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants/not-a-uuid');

    $response->assertNotFound();
});

/**
 * The admin customer list's billing columns (plan, billing_interval,
 * status, trial_ends_at, auto_renew, next_renewal_date) — added so an
 * admin scanning /admin can see who's past_due without drilling into
 * each tenant individually. Three real tenant states in one list: a
 * trialing tenant with no Subscription row at all (auto_renew/
 * next_renewal_date must be null, not a crash), a real active
 * subscriber (auto_renew true, a real next_renewal_date), and a real
 * past_due one produced via the actual transaction.payment_failed
 * webhook path (PaddleWebhookController), not a hand-set status —
 * proving the list reflects the same state Settings/Billing itself
 * would show that tenant.
 */
test('the tenant list surfaces real per-tenant billing state, correctly attributed and never crossed between tenants', function () {
    [$adminToken] = seedAdminAccount('Billing Columns Admin');

    [, $trialingTenantId] = seedCustomerAccount('Billing Columns Trialing');
    DB::transaction(function () use ($trialingTenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $trialingTenantId]);
        \App\Models\Tenant::find($trialingTenantId)->startTrial('standard');
    });

    [, $activeTenantId] = seedCustomerAccount('Billing Columns Active');
    $activeOwnerId = ownerIdFor($activeTenantId);
    activatePaddleSubscriptionForTenant($activeTenantId, $activeOwnerId);

    [, $pastDueTenantId] = seedCustomerAccount('Billing Columns Past Due');
    $pastDueOwnerId = ownerIdFor($pastDueTenantId);
    $pastDueSubscriptionId = activatePaddleSubscriptionForTenant($pastDueTenantId, $pastDueOwnerId);
    $pastDueCustomerId = DB::transaction(function () use ($pastDueTenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $pastDueTenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $pastDueTenantId)->value('paddle_id');
    });
    postSignedPaddleWebhook(
        transactionPaymentFailedPayload($pastDueCustomerId, $pastDueSubscriptionId, $pastDueTenantId)
    )->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/tenants');
    $response->assertOk();

    $rows = collect($response->json('data'))->keyBy('id');

    $trialing = $rows->get($trialingTenantId);
    expect($trialing['status'])->toBe('trialing');
    expect($trialing['auto_renew'])->toBeNull();
    expect($trialing['next_renewal_date'])->toBeNull();
    expect($trialing['trial_ends_at'])->not->toBeNull();

    $active = $rows->get($activeTenantId);
    expect($active['status'])->toBe('active');
    expect($active['billing_interval'])->toBe('monthly');
    expect($active['auto_renew'])->toBeTrue();
    expect($active['next_renewal_date'])->not->toBeNull();

    $pastDue = $rows->get($pastDueTenantId);
    expect($pastDue['status'])->toBe('past_due');
    // Still auto-renewing — Paddle is retrying, not treating this as
    // canceled (same distinction BillingSection.tsx's own past_due
    // copy relies on).
    expect($pastDue['auto_renew'])->toBeTrue();

    // One audit_logs row for the whole list call, not one per tenant/
    // subscription looked up along the way.
    $logs = auditLogsForAction('admin.tenants.list')->where('target', 'all');
    expect($logs->count())->toBe(1);
});

/**
 * The drill-in's payment_logs section (Step 2 of the payment-history
 * work — Step 1 built the table itself). Real succeeded and failed rows,
 * produced via the actual webhook path (PaddleTransitionDataIntegrityTest/
 * PaymentLogTest's own standard), not hand-inserted rows — proves the
 * drill-in surfaces what the webhook handler actually wrote, most-recent
 * first, and never leaks another tenant's payment history into this one.
 */
test('the tenant drill-in surfaces that tenant\'s real payment history, most recent first, audited once, never crossed with another tenant', function () {
    [$adminToken] = seedAdminAccount('Payment History Admin');

    [, $tenantId] = seedCustomerAccount('Payment History Target');
    $ownerId = ownerIdFor($tenantId);
    $subscriptionId = activatePaddleSubscriptionForTenant($tenantId, $ownerId);
    $customerId = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $tenantId)->value('paddle_id');
    });

    // A second, unrelated tenant with its own payment history — the real
    // cross-tenant-leak check below has something concrete to fail
    // against, not just an empty-by-coincidence array.
    [, $otherTenantId] = seedCustomerAccount('Payment History Other');
    $otherOwnerId = ownerIdFor($otherTenantId);
    $otherSubscriptionId = activatePaddleSubscriptionForTenant($otherTenantId, $otherOwnerId);
    $otherCustomerId = DB::transaction(function () use ($otherTenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $otherTenantId]);

        return \App\Models\Customer::query()->where('tenant_id', $otherTenantId)->value('paddle_id');
    });
    $otherPayload = transactionCompletedPayload($otherCustomerId, $otherSubscriptionId, $otherTenantId);
    postSignedPaddleWebhook($otherPayload)->assertOk();

    // Two real events for the target tenant, deliberately out of
    // chronological order (succeeded happened first, failed second) —
    // Carbon::setTestNow() makes the ordering assertion below meaningful
    // instead of relying on two real webhook posts landing in the same
    // wall-clock second.
    \Illuminate\Support\Carbon::setTestNow(now()->subMinutes(10));
    $succeededPayload = transactionCompletedPayload($customerId, $subscriptionId, $tenantId);
    postSignedPaddleWebhook($succeededPayload)->assertOk();

    \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(10));
    $failedPayload = transactionPaymentFailedPayload($customerId, $subscriptionId, $tenantId, errorCode: 'expired_card');
    postSignedPaddleWebhook($failedPayload)->assertOk();
    \Illuminate\Support\Carbon::setTestNow();

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson("/api/v1/admin/tenants/{$tenantId}");

    $response->assertOk();
    $logs = $response->json('data.payment_logs');

    expect($logs)->toHaveCount(2);
    // Most recent first: the failed attempt (posted 10 minutes after the
    // succeeded one) is index 0.
    expect($logs[0]['status'])->toBe('failed');
    expect($logs[0]['failure_reason'])->toBe('expired_card');
    expect($logs[0]['amount'])->toBe('2000');
    expect($logs[0]['currency'])->toBe('USD');
    expect($logs[1]['status'])->toBe('succeeded');
    expect($logs[1]['failure_reason'])->toBeNull();

    // Every transaction id in this tenant's list traces back to one of
    // the two webhooks posted for it above — the other tenant's own
    // successful payment (a real, different paddle_transaction_id) never
    // appears here.
    $transactionIds = collect($logs)->pluck('paddle_transaction_id');
    expect($transactionIds->all())->toEqualCanonicalizing([$failedPayload['data']['id'], $succeededPayload['data']['id']]);
    expect($transactionIds)->not->toContain($otherPayload['data']['id']);

    // Still exactly one audit_logs row for this view — the extra
    // PaymentLog read doesn't produce a second, separately-audited entry.
    $auditRows = auditLogsForAction('admin.tenants.view')->where('target', $tenantId);
    expect($auditRows->count())->toBe(1);
});
