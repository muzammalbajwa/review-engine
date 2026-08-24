<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * RequireSendingAccess (app/Http/Middleware/RequireSendingAccess.php).
 * "A tenant with status = 'trial_expired' can still log in, view their
 * dashboard, contacts, templates, and past campaign history — but cannot
 * send new review requests, use quick-add, or hit the webhook API. Show
 * a clear, honest banner/message rather than a silent block or a
 * confusing error."
 */
function expireTrialDirect(string $tenantId): void
{
    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = Tenant::find($tenantId);
        $tenant->plan = 'standard';
        $tenant->status = 'trial_expired';
        $tenant->trial_started_at = now()->subDays(8);
        $tenant->trial_ends_at = now()->subDay();
        $tenant->save();
    });
}

function quickAddTokenForGating(string $tenantId): string
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return Tenant::withoutGlobalScopes()->find($tenantId)->quick_add_token;
    });
}

test('a trial_expired tenant cannot import a CSV of new contacts', function () {
    [$token, $tenantId] = seedCustomerAccount('Gating CSV Import');
    expireTrialDirect($tenantId);

    $csv = "Name,Phone\nBlocked Contact,555-0100\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import', [
            'file' => $file,
            'mapping' => ['name' => 'Name', 'phone' => 'Phone'],
        ]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('trial_expired');
    expect($response->json('message'))->toContain('subscribe');
});

test('a trial_expired tenant cannot use authenticated quick-add', function () {
    [$token, $tenantId] = seedCustomerAccount('Gating Auth Quick Add');
    expireTrialDirect($tenantId);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Blocked', 'phone' => '555-0100']);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('trial_expired');
});

test('a trial_expired tenant\'s public guest quick-add link is also blocked', function () {
    [, $tenantId] = seedCustomerAccount('Gating Guest Quick Add');
    expireTrialDirect($tenantId);
    $quickToken = quickAddTokenForGating($tenantId);

    $response = $this->postJson("/api/v1/quick/{$quickToken}", ['name' => 'Blocked Guest', 'phone' => '555-0100']);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('trial_expired');

    // The guest-facing GET (confirms "you're on the right link") still
    // works — only the write is blocked, so a mid-trial-expiry guest sees
    // a clear reason, not a broken/missing page.
    $this->getJson("/api/v1/quick/{$quickToken}")->assertOk();
});

test('a trial_expired tenant\'s webhook API key can no longer create contacts', function () {
    [$token, $tenantId] = seedCustomerAccount('Gating Webhook API');
    expireTrialDirect($tenantId);

    $apiKey = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/api-keys')
        ->assertCreated()
        ->json('data.token');

    $response = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Blocked Webhook Contact', 'phone' => '555-0100']);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('trial_expired');
});

test('a trial_expired tenant keeps full read access to their dashboard, contacts, templates, and campaign history', function () {
    [$token, $tenantId] = seedCustomerAccount('Gating Read Access');
    expireTrialDirect($tenantId);

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        $contact = new Contact([
            'campaign_id' => $campaign->id,
            'name' => 'Existing Contact',
            'phone' => '555-0100',
            'status' => 'pending',
        ]);
        $contact->tenant_id = $tenantId;
        $contact->save();
    });

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

    $auth()->getJson('/api/v1/tenant')->assertOk();
    $auth()->getJson('/api/v1/onboarding/status')->assertOk();
    $auth()->getJson('/api/v1/contacts')->assertOk()->assertJsonPath('data.total', 1);
    $auth()->getJson('/api/v1/templates')->assertOk();
    $auth()->getJson('/api/v1/reviews')->assertOk();
    $auth()->getJson('/api/v1/analytics/campaign')->assertOk();
    // The import wizard's non-persisting preview step stays open too —
    // only the actual import (a real send) is gated.
    $csv = "Name,Phone\nPreview Only,555-0199\n";
    $auth()->post('/api/v1/contacts/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ])->assertOk();
});

test('the /tenant endpoint reports trial_expired status and the original trial_ends_at for the banner', function () {
    [$token, $tenantId] = seedCustomerAccount('Gating Banner Data');
    expireTrialDirect($tenantId);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tenant');

    $response->assertOk();
    expect($response->json('data.status'))->toBe('trial_expired');
    expect($response->json('data.plan'))->toBe('standard');
    expect($response->json('data.trial_ends_at'))->not->toBeNull();
});

test('a tenant still trialing (not yet expired) is not blocked from sending', function () {
    [$token, $tenantId] = seedCustomerAccount('Gating Still Trialing');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Still Trialing OK', 'phone' => '555-0100'])
        ->assertCreated();
});

test('trial-expiry gating on one tenant never affects another tenant\'s ability to send', function () {
    [, $expiredTenantId] = seedCustomerAccount('Gating Isolation Expired');
    expireTrialDirect($expiredTenantId);

    [$okToken] = seedCustomerAccount('Gating Isolation Fine');
    $this->withHeader('Authorization', "Bearer {$okToken}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$okToken}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Fine Tenant Contact', 'phone' => '555-0100'])
        ->assertCreated();
});
