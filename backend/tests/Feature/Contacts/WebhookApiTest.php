<?php

use App\Models\Contact;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * .claude/CLAUDE.md: "a public, versioned Webhook API as a first-class
 * product surface... treat it with the same rigor as the Lemon Squeezy
 * webhook handling already in this codebase." POST /api/v1/contacts.
 */
function issueApiKey(string $sessionToken): string
{
    return test()->withHeader('Authorization', "Bearer {$sessionToken}")
        ->postJson('/api/v1/api-keys')
        ->json('data.token');
}

/**
 * Plain Contact::query() calls made from test code (not inside a
 * simulated HTTP request) run with no tenant context active at all —
 * RLS correctly returns zero rows rather than erroring, same as any
 * other unauthenticated read. The is_admin bypass here mirrors every
 * other cross-request assertion in this suite (tests/Helpers.php's
 * auditLogsForAction, for the same reason).
 */
function contactCount(?string $externalId = null): int
{
    return DB::transaction(function () use ($externalId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return Contact::withoutGlobalScopes()
            ->when($externalId !== null, fn ($q) => $q->where('external_id', $externalId))
            ->count();
    });
}

function backdateContact(int $contactId, Carbon $createdAt): void
{
    DB::transaction(function () use ($contactId, $createdAt) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        Contact::withoutGlobalScopes()->findOrFail($contactId)->forceFill(['created_at' => $createdAt])->save();
    });
}

test('the webhook endpoint requires authentication', function () {
    $this->postJson('/api/v1/contacts', ['name' => 'No Key', 'phone' => '555-0100'])
        ->assertUnauthorized();
});

test('an invalid api key is rejected cleanly with the standard error envelope', function () {
    $response = $this->withHeader('Authorization', 'Bearer not-a-real-key-at-all')
        ->postJson('/api/v1/contacts', ['name' => 'Bad Key', 'phone' => '555-0100']);

    $response->assertUnauthorized();
    expect($response->json('error'))->toBe('unauthenticated');
    expect($response->json())->toHaveKeys(['error', 'message', 'fields']);
});

test('a valid key but wrong ability is rejected with 403, not silently allowed', function () {
    [$sessionToken, $tenantId] = seedCustomerAccount('Wrong Ability');

    $owner = DB::transaction(function () use ($tenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return User::withoutGlobalScopes()->where('tenant_id', $tenantId)->firstOrFail();
    });
    $wrongAbilityToken = $owner->createToken('wrong-scope', ['reviews:read'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$wrongAbilityToken}")
        ->postJson('/api/v1/contacts', ['name' => 'Wrong Scope', 'phone' => '555-0100']);

    $response->assertForbidden();
    expect($response->json('error'))->toBe('forbidden');
});

test('an ability check rejects a wrong-scope token before it can consume rate-limit budget', function () {
    [$sessionToken, $tenantId] = seedCustomerAccount('Ability Before Throttle');

    $owner = DB::transaction(function () use ($tenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return User::withoutGlobalScopes()->where('tenant_id', $tenantId)->firstOrFail();
    });
    $wrongAbilityToken = $owner->createToken('wrong-scope', ['reviews:read'])->plainTextToken;

    // Exhaust the tenant's entire rate-limit budget first, using the same
    // wrong-ability token. If the ability check genuinely runs before the
    // throttle check (as the route's declared middleware order requires:
    // 'abilities:contacts:create' before 'throttle:webhook-api'), none of
    // these requests should ever consume rate-limit budget in the first
    // place, and every single one — including this last one — must 403,
    // never 429. A 429 here means the throttle middleware ran first and
    // silently masked the real (auth-scope) problem.
    $limit = config('plans.starter.requests_per_minute');

    for ($i = 0; $i <= $limit; $i++) {
        $response = $this->withHeader('Authorization', "Bearer {$wrongAbilityToken}")
            ->postJson('/api/v1/contacts', ['name' => "Attempt {$i}", 'phone' => '555-0150']);

        expect($response->status())->toBe(403);
        expect($response->json('error'))->toBe('forbidden');
    }
});

test('malformed payloads are rejected with the standard 422 envelope', function () {
    [$sessionToken] = seedCustomerAccount('Malformed Payload');
    $apiKey = issueApiKey($sessionToken);

    $noName = $this->withHeader('Authorization', "Bearer {$apiKey}")->postJson('/api/v1/contacts', ['phone' => '555-0100']);
    $noName->assertStatus(422);
    expect($noName->json())->toHaveKeys(['error', 'message', 'fields']);
    expect($noName->json('fields.name'))->not->toBeNull();

    $noContactMethod = $this->withHeader('Authorization', "Bearer {$apiKey}")->postJson('/api/v1/contacts', ['name' => 'Nobody']);
    $noContactMethod->assertStatus(422);
    expect($noContactMethod->json('fields.phone'))->not->toBeNull();
});

test('a valid request creates a contact enrolled in the same campaign a CSV import would use', function () {
    [$sessionToken] = seedCustomerAccount('Valid Webhook Create');
    $apiKey = issueApiKey($sessionToken);

    $csv = "Name,Phone\nCSV Sibling,555-0101\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);
    $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->post('/api/v1/contacts/import', ['file' => $file, 'mapping' => ['name' => 'Name', 'phone' => 'Phone']])
        ->assertCreated();

    $response = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Webhook Lead', 'phone' => '555-0102', 'external_id' => 'evt-1']);

    $response->assertCreated();
    expect($response->json('data.source'))->toBe('webhook');
    expect($response->json('data.status'))->toBe('pending');
    expect($response->json('data.external_id'))->toBe('evt-1');

    $contacts = $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->getJson('/api/v1/contacts')->json('data.data');
    $csvContact = collect($contacts)->firstWhere('name', 'CSV Sibling');
    $webhookContact = collect($contacts)->firstWhere('name', 'Webhook Lead');

    expect($webhookContact['campaign_id'])->toBe($csvContact['campaign_id']);
});

test('a duplicate external_id within the window returns the original contact, not a new one', function () {
    [$sessionToken] = seedCustomerAccount('Idempotent Retry');
    $apiKey = issueApiKey($sessionToken);

    $first = $this->withHeader('Authorization', "Bearer {$apiKey}")->postJson('/api/v1/contacts', [
        'name' => 'Original Name',
        'phone' => '555-0200',
        'external_id' => 'retry-evt-1',
    ]);
    $first->assertCreated();
    $originalId = $first->json('data.id');

    // A real retry from a webhook sender would resend the exact same
    // payload — but even a *different* payload with the same external_id
    // must return the original, proving this is a real dedupe, not an
    // accidental match on identical field values.
    $retry = $this->withHeader('Authorization', "Bearer {$apiKey}")->postJson('/api/v1/contacts', [
        'name' => 'A Totally Different Name',
        'phone' => '555-9999',
        'external_id' => 'retry-evt-1',
    ]);

    $retry->assertStatus(200);
    expect($retry->json('data.id'))->toBe($originalId);
    expect($retry->json('data.name'))->toBe('Original Name');

    expect(contactCount('retry-evt-1'))->toBe(1);
});

test('a different external_id is not deduped against an unrelated one', function () {
    [$sessionToken] = seedCustomerAccount('Distinct External Ids');
    $apiKey = issueApiKey($sessionToken);

    $first = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'First', 'phone' => '555-0300', 'external_id' => 'a']);
    $second = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Second', 'phone' => '555-0301', 'external_id' => 'b']);

    $first->assertCreated();
    $second->assertCreated();
    expect($first->json('data.id'))->not->toBe($second->json('data.id'));
});

test('omitting external_id never dedupes at all', function () {
    [$sessionToken] = seedCustomerAccount('No External Id');
    $apiKey = issueApiKey($sessionToken);

    $first = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Same Name', 'phone' => '555-0400']);
    $second = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Same Name', 'phone' => '555-0400']);

    $first->assertCreated();
    $second->assertCreated();
    expect($first->json('data.id'))->not->toBe($second->json('data.id'));
});

test('the same external_id string never collides across two different tenants', function () {
    [$tokenA] = seedCustomerAccount('External Id Tenant A');
    [$tokenB] = seedCustomerAccount('External Id Tenant B');
    $keyA = issueApiKey($tokenA);
    $keyB = issueApiKey($tokenB);

    $a = $this->withHeader('Authorization', "Bearer {$keyA}")
        ->postJson('/api/v1/contacts', ['name' => 'Tenant A Lead', 'phone' => '555-0500', 'external_id' => 'shared-id']);
    $b = $this->withHeader('Authorization', "Bearer {$keyB}")
        ->postJson('/api/v1/contacts', ['name' => 'Tenant B Lead', 'phone' => '555-0501', 'external_id' => 'shared-id']);

    $a->assertCreated();
    $b->assertCreated();
    expect($a->json('data.id'))->not->toBe($b->json('data.id'));
});

test('a stale duplicate outside the idempotency window creates a new contact instead of returning the old one', function () {
    [$sessionToken] = seedCustomerAccount('Stale Window');
    $apiKey = issueApiKey($sessionToken);

    $first = $this->withHeader('Authorization', "Bearer {$apiKey}")->postJson('/api/v1/contacts', [
        'name' => 'Old Event',
        'phone' => '555-0600',
        'external_id' => 'old-evt',
    ]);
    $first->assertCreated();

    backdateContact($first->json('data.id'), now()->subHours(25));

    $second = $this->withHeader('Authorization', "Bearer {$apiKey}")->postJson('/api/v1/contacts', [
        'name' => 'New Event, Same External Id',
        'phone' => '555-0601',
        'external_id' => 'old-evt',
    ]);

    $second->assertCreated();
    expect($second->json('data.id'))->not->toBe($first->json('data.id'));
});

test('exceeding the plan rate limit returns 429 with a Retry-After header, not a silent drop', function () {
    [$sessionToken] = seedCustomerAccount('Rate Limited Webhook');
    $apiKey = issueApiKey($sessionToken);

    // One flat limit now — there's only one plan (config/plans.php).
    $limit = config('plans.standard.requests_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $response = $this->withHeader('Authorization', "Bearer {$apiKey}")
            ->postJson('/api/v1/contacts', ['name' => "Bulk {$i}", 'phone' => '555-0700']);

        expect($response->status())->not->toBe(429);
    }

    $response = $this->withHeader('Authorization', "Bearer {$apiKey}")
        ->postJson('/api/v1/contacts', ['name' => 'One Too Many', 'phone' => '555-0700']);

    $response->assertStatus(429);
    expect($response->json('error'))->toBe('rate_limited');
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

test('two different tenants have independent rate limit budgets', function () {
    [$tokenA] = seedCustomerAccount('Rate Limit Tenant A');
    [$tokenB] = seedCustomerAccount('Rate Limit Tenant B');
    $keyA = issueApiKey($tokenA);
    $keyB = issueApiKey($tokenB);

    $limit = config('plans.standard.requests_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $this->withHeader('Authorization', "Bearer {$keyA}")
            ->postJson('/api/v1/contacts', ['name' => "A{$i}", 'phone' => '555-0800']);
    }

    // Tenant A is now exhausted; tenant B, on its own budget, is not.
    $this->withHeader('Authorization', "Bearer {$keyA}")
        ->postJson('/api/v1/contacts', ['name' => 'A overflow', 'phone' => '555-0800'])
        ->assertStatus(429);

    $this->withHeader('Authorization', "Bearer {$keyB}")
        ->postJson('/api/v1/contacts', ['name' => 'B first request', 'phone' => '555-0801'])
        ->assertCreated();
});

test('key rotation does not break an in-flight caller using the old key during the grace period', function () {
    [$sessionToken] = seedCustomerAccount('Rotation In Flight');
    $oldKey = issueApiKey($sessionToken);

    // The old key is already in active use...
    $this->withHeader('Authorization', "Bearer {$oldKey}")
        ->postJson('/api/v1/contacts', ['name' => 'Before Rotation', 'phone' => '555-0900'])
        ->assertCreated();

    // ...when the tenant rotates to a new key...
    $newKey = issueApiKey($sessionToken);
    expect($newKey)->not->toBe($oldKey);

    // ...the old key must keep working during its grace period...
    $this->withHeader('Authorization', "Bearer {$oldKey}")
        ->postJson('/api/v1/contacts', ['name' => 'During Grace Period', 'phone' => '555-0901'])
        ->assertCreated();

    // ...and the new key works too, immediately.
    $this->withHeader('Authorization', "Bearer {$newKey}")
        ->postJson('/api/v1/contacts', ['name' => 'On New Key', 'phone' => '555-0902'])
        ->assertCreated();
});

test('a key stops working via the webhook endpoint specifically once its grace period elapses', function () {
    [$sessionToken] = seedCustomerAccount('Grace Elapsed Webhook');
    $oldKey = issueApiKey($sessionToken);
    issueApiKey($sessionToken); // rotate

    expireTokenNow($oldKey);

    $response = $this->withHeader('Authorization', "Bearer {$oldKey}")
        ->postJson('/api/v1/contacts', ['name' => 'After Grace', 'phone' => '555-0903']);

    $response->assertUnauthorized();
});
