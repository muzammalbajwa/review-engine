<?php

use App\Http\Controllers\Api\V1\ContactController;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * .claude/API.md: GET /contacts/webhook-activity — Settings' "is this
 * actually working" panel, .claude/FRONTEND.md.
 */
function issueWebhookApiKey(string $sessionToken): string
{
    return test()->withHeader('Authorization', "Bearer {$sessionToken}")
        ->postJson('/api/v1/api-keys')
        ->json('data.token');
}

function backdateContactByName(string $name, Carbon $createdAt): void
{
    DB::transaction(function () use ($name, $createdAt) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        Contact::withoutGlobalScopes()
            ->where('name', $name)
            ->firstOrFail()
            ->forceFill(['created_at' => $createdAt])
            ->save();
    });
}

test('webhook activity requires authentication', function () {
    $this->getJson('/api/v1/contacts/webhook-activity')->assertUnauthorized();
});

test('a fresh tenant has no webhook activity', function () {
    [$token] = seedCustomerAccount('No Activity Yet');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/contacts/webhook-activity');

    $response->assertOk();
    expect($response->json('data.count'))->toBe(0);
    expect($response->json('data.window_days'))->toBe(7);
    expect($response->json('data.recent'))->toBe([]);
});

test('counts real webhook contacts from the last 7 days and lists the most recent', function () {
    [$sessionToken] = seedCustomerAccount('Real Activity');
    $apiKey = issueWebhookApiKey($sessionToken);

    foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
        $this->withHeader('Authorization', "Bearer {$apiKey}")
            ->postJson('/api/v1/contacts', ['name' => $name, 'phone' => '+15555550100'])
            ->assertCreated();
    }

    $response = $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->getJson('/api/v1/contacts/webhook-activity');

    $response->assertOk();
    expect($response->json('data.count'))->toBe(3);
    expect(collect($response->json('data.recent'))->pluck('name'))->toContain('Alpha', 'Bravo', 'Charlie');
});

test('excludes contacts older than the 7-day window', function () {
    [$sessionToken] = seedCustomerAccount('Stale Activity');
    $apiKey = issueWebhookApiKey($sessionToken);

    $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Too Old', 'phone' => '+15555550100'])
        ->assertCreated();
    backdateContactByName('Too Old', now()->subDays(8));

    $response = $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->getJson('/api/v1/contacts/webhook-activity');

    expect($response->json('data.count'))->toBe(0);
});

test('excludes contacts from other sources', function () {
    [$sessionToken] = seedCustomerAccount('Other Sources');

    $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Quick Added', 'phone' => '+15555550100'])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->getJson('/api/v1/contacts/webhook-activity');

    expect($response->json('data.count'))->toBe(0);
});

test('excludes the Settings "send test event" button\'s own contacts from the count', function () {
    [$sessionToken] = seedCustomerAccount('Self Test Excluded');
    $apiKey = issueWebhookApiKey($sessionToken);

    // A real integration call...
    $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Real Lead', 'phone' => '+15555550100'])
        ->assertCreated();

    // ...and the tenant clicking "Send test event" themselves, using the
    // exact external_id prefix that button uses.
    $prefix = ContactController::SETTINGS_TEST_EXTERNAL_ID_PREFIX;
    $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->postJson('/api/v1/contacts', [
            'name' => 'Test Contact',
            'phone' => '+15555550199',
            'external_id' => $prefix.now()->timestamp,
        ])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->getJson('/api/v1/contacts/webhook-activity');

    expect($response->json('data.count'))->toBe(1);
    $names = collect($response->json('data.recent'))->pluck('name');
    expect($names)->toContain('Real Lead');
    expect($names)->not->toContain('Test Contact');
});
