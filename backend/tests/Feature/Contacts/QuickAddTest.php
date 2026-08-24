<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * .claude/CLAUDE.md quick-add: "creates one contact and immediately
 * enrolls them in the existing drip engine's message-1 step. Reuse that
 * pipeline exactly." The only pipeline that currently exists is
 * "land in the tenant's campaign as a pending contact" (there is no
 * send-job/scheduler yet — CsvImportService::import() doesn't dispatch
 * one either) — so "identical pipeline" is verified here as: same
 * campaign as a CSV-imported contact for the same tenant, same
 * status='pending', distinguished only by `source`.
 */
function quickAddTokenFor(string $tenantId): string
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return Tenant::withoutGlobalScopes()->find($tenantId)->quick_add_token;
    });
}

/**
 * seedCustomerAccount() (tests/Helpers.php) goes through the real
 * POST /register endpoint, which is itself throttle:5,1 (SECURITY.md #3)
 * — fine for one or two tenants, but the per-ip rate-limit test below
 * needs six in one test, from the same client ip, which would trip
 * register's own limiter before ever reaching quick-add's. This creates
 * the tenant/user/token directly, the same way seedAdminAccount()
 * (tests/Helpers.php) does for admin tenants, bypassing both /register
 * and /login entirely — deliberately, since what's under test here is
 * quick-add's own limiter, not register's.
 */
function seedCustomerAccountDirect(string $label): array
{
    $tenantId = (string) Str::uuid();
    $email = strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com';

    $token = DB::transaction(function () use ($tenantId, $label, $email) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => "{$label} Co", 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => $email,
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        return $user->createToken('test')->plainTextToken;
    });

    return [$token, $tenantId];
}

test('authenticated quick-add requires authentication', function () {
    $this->postJson('/api/v1/contacts/quick-add', ['name' => 'No Auth', 'phone' => '555-0100'])
        ->assertUnauthorized();
});

test('authenticated quick-add requires a name and at least one of phone or email', function () {
    [$token] = seedCustomerAccount('Quick Add Validation');

    $empty = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', []);
    $empty->assertStatus(422);
    expect($empty->json('fields.name'))->not->toBeNull();
    expect($empty->json('fields.phone'))->not->toBeNull();

    $noContactMethod = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Jane Doe']);
    $noContactMethod->assertStatus(422);
    expect($noContactMethod->json('fields.phone'))->not->toBeNull();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Jane Doe', 'email' => 'jane@example.com'])
        ->assertCreated();
});

test('an authenticated quick-add lands in the same campaign as a CSV-imported contact for the same tenant', function () {
    [$token] = seedCustomerAccount('Quick Add Same Pipeline');

    $csv = "Name,Phone\nCSV Contact,555-0101\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import', [
            'file' => $file,
            'mapping' => ['name' => 'Name', 'phone' => 'Phone'],
        ])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/contacts/quick-add', ['name' => 'Quick Contact', 'phone' => '555-0102'])
        ->assertCreated();

    $contacts = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/contacts')
        ->json('data.data');

    $csvContact = collect($contacts)->firstWhere('name', 'CSV Contact');
    $quickContact = collect($contacts)->firstWhere('name', 'Quick Contact');

    expect($csvContact['source'])->toBe('csv_import');
    expect($quickContact['source'])->toBe('quick_add');
    expect($quickContact['status'])->toBe('pending');
    expect($quickContact['campaign_id'])->toBe($csvContact['campaign_id']);
});

test('the guest quick-add form endpoint returns the business name for a valid token', function () {
    [, $tenantId] = seedCustomerAccount('Quick Add Guest Show');
    $token = quickAddTokenFor($tenantId);

    $response = $this->getJson("/api/v1/quick/{$token}");

    $response->assertOk();
    expect($response->json('data.business_name'))->toBe('Quick Add Guest Show Co');
});

test('the guest quick-add form endpoint 404s for an invalid token, not a 500 or leaked detail', function () {
    $response = $this->getJson('/api/v1/quick/'.str_repeat('x', 48));

    $response->assertNotFound();
    expect($response->json('error'))->toBe('not_found');
});

test('a guest can add a contact via a valid tenant token with no authentication at all', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Quick Add Guest Store');
    $token = quickAddTokenFor($tenantId);

    $response = $this->postJson("/api/v1/quick/{$token}", [
        'name' => 'Field Site Customer',
        'phone' => '555-0199',
    ]);

    $response->assertCreated();

    $contacts = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->getJson('/api/v1/contacts')
        ->json('data.data');

    $created = collect($contacts)->firstWhere('name', 'Field Site Customer');
    expect($created)->not->toBeNull();
    expect($created['source'])->toBe('quick_add');
    expect($created['status'])->toBe('pending');
});

test('a guest contact created via tenant A\'s link never appears for tenant B, and vice versa', function () {
    [$tokenA, $tenantAId] = seedCustomerAccount('Quick Add Isolation A');
    [$tokenB, $tenantBId] = seedCustomerAccount('Quick Add Isolation B');
    $quickTokenA = quickAddTokenFor($tenantAId);

    $this->postJson("/api/v1/quick/{$quickTokenA}", ['name' => 'Tenant A Customer', 'email' => 'a@example.com'])
        ->assertCreated();

    $tenantAContacts = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/contacts')->json('data.data');
    $tenantBContacts = $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/contacts')->json('data.data');

    expect(collect($tenantAContacts)->pluck('name'))->toContain('Tenant A Customer');
    expect(collect($tenantBContacts)->pluck('name'))->not->toContain('Tenant A Customer');
});

test('one tenant\'s quick-add token can never resolve to a different tenant\'s data', function () {
    [, $tenantAId] = seedCustomerAccount('Quick Add Token Scope A');
    [, $tenantBId] = seedCustomerAccount('Quick Add Token Scope B');
    $tokenA = quickAddTokenFor($tenantAId);

    $response = $this->getJson("/api/v1/quick/{$tokenA}");

    $response->assertOk();
    expect($response->json('data.business_name'))->toBe('Quick Add Token Scope A Co');
    expect($response->json('data.business_name'))->not->toBe('Quick Add Token Scope B Co');
});

test('the guest quick-add endpoint is rate-limited per token and cannot be spammed', function () {
    [, $tenantId] = seedCustomerAccount('Quick Add Rate Limit');
    $token = quickAddTokenFor($tenantId);

    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson("/api/v1/quick/{$token}", [
            'name' => "Spam {$i}",
            'phone' => '555-0100',
        ]);

        expect($response->status())->not->toBe(429);
    }

    $response = $this->postJson("/api/v1/quick/{$token}", [
        'name' => 'One Too Many',
        'phone' => '555-0100',
    ]);

    $response->assertStatus(429);
    expect($response->json('error'))->toBe('rate_limited');
});

test('the guest quick-add endpoint is rate-limited per ip across many different tokens, resisting enumeration', function () {
    // Every request in a Pest HTTP test shares the same client "ip", so
    // this isolates the ip-keyed limiter specifically: 5 tokens x 4
    // requests each stays under every individual token's own 5/min cap,
    // but the cumulative total (20) hits the 20/min per-ip cap exactly —
    // a 21st request, to a brand new 6th token making its own first-ever
    // request, still has to 429 purely on ip volume.
    $tokens = collect(range(1, 6))
        ->map(fn ($i) => seedCustomerAccountDirect("Quick Add Enum {$i}"))
        ->map(fn ($account) => quickAddTokenFor($account[1]));

    $statuses = [];

    foreach ($tokens->take(5) as $token) {
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->postJson("/api/v1/quick/{$token}", ['name' => "Enum {$i}", 'phone' => '555-0100'])
                ->status();
        }
    }

    expect($statuses)->not->toContain(429);

    $overflow = $this->postJson("/api/v1/quick/{$tokens->last()}", ['name' => 'Overflow', 'phone' => '555-0100']);

    $overflow->assertStatus(429);
    expect($overflow->json('error'))->toBe('rate_limited');
});
