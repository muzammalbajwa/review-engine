<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * CRITICAL (task item 5): "trial expiry must never delete, soft-delete,
 * or otherwise touch any tenant data — contacts, campaigns, templates,
 * messages all remain fully intact and viewable." trial:expire
 * (ExpireStaleTrials) only ever writes tenants.status — this proves that
 * end to end: real rows created during a real trial, the trial actually
 * expires via the real scheduled command, and every single row (and
 * every column on it) is asserted byte-for-byte unchanged afterward.
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

test('CRITICAL: trial expiry never deletes, soft-deletes, or modifies any tenant data', function () {
    [$token, $tenantId] = seedCustomerAccount('Data Integrity');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    $seeded = seedTrialEraData($tenantId);
    expect($seeded['contacts'])->toHaveCount(3);

    $before = tenantDataSnapshot($tenantId);
    expect($before['campaigns'])->toHaveCount(1);
    expect($before['contacts'])->toHaveCount(3);
    expect($before['templates'])->toHaveCount(1);
    expect($before['messages'])->toHaveCount(1);

    // Push trial_ends_at into the past and run the real scheduled command
    // — not a direct ->update(['status' => ...]) in the test, so this
    // exercises the actual code path that will run in production.
    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        $tenant = Tenant::find($tenantId);
        $tenant->trial_started_at = now()->subDays(8);
        $tenant->trial_ends_at = now()->subDay();
        $tenant->save();
    });

    $this->artisan('trial:expire')->assertSuccessful();

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/onboarding/status');
    expect($status->json('data.tenant_status'))->toBe('trial_expired');

    $after = tenantDataSnapshot($tenantId);

    // Row counts unchanged — nothing deleted, nothing extra created.
    expect($after['campaigns'])->toHaveCount(1);
    expect($after['contacts'])->toHaveCount(3);
    expect($after['templates'])->toHaveCount(1);
    expect($after['messages'])->toHaveCount(1);

    // Every column on every row, byte-for-byte identical — proves
    // trial:expire touched only tenants.status, nothing else.
    expect($after)->toEqual($before);

    // Still fully readable through the real, authenticated API too — not
    // just present in the database.
    $contactsResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/contacts');
    $contactsResponse->assertOk()->assertJsonPath('data.total', 3);

    // Not asserted at an exact count here: GET /templates auto-provisions
    // any of the 3 steps that don't have a saved template yet
    // (TemplateProvisioner::ensureDefaults, an existing, unrelated
    // feature — nothing to do with trial expiry) the first time it's
    // read, so its count reflects that provisioning, not the raw
    // templates table this test's real assertion (tenantDataSnapshot's
    // toEqual comparison above) already checked byte-for-byte. This just
    // confirms the endpoint itself still works and still returns this
    // tenant's real, custom step-1 body.
    $templatesResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/templates');
    $templatesResponse->assertOk();
    $stepOne = collect($templatesResponse->json('data'))->firstWhere('step', 1);
    expect($stepOne['body'])->toBe($seeded['template']->body);
});

test('CRITICAL: contacts already pending before trial expiry are preserved (not deleted) even though they stop being released', function () {
    [$token, $tenantId] = seedCustomerAccount('Data Integrity Pending Release');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/onboarding/start-trial', ['plan' => 'standard'])
        ->assertCreated();

    makeVerifiedSenderIdentity($tenantId);
    makeConnectedGbpConnection($tenantId);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Still Pending At Expiry', 'phone' => '555-0155'])
        ->assertCreated();

    DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        $tenant = Tenant::find($tenantId);
        $tenant->trial_started_at = now()->subDays(8);
        $tenant->trial_ends_at = now()->subDay();
        $tenant->save();
    });

    $this->artisan('trial:expire')->assertSuccessful();

    // drip:release-pending's own trial-expiry guard stops the release —
    // it does NOT touch the contact row itself.
    $this->artisan('drip:release-pending')->assertSuccessful();

    $contact = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Contact::where('name', 'Still Pending At Expiry')->first();
    });

    expect($contact)->not->toBeNull();
    expect($contact->status)->toBe('pending');
});
