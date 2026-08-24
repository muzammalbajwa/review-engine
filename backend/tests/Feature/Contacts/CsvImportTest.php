<?php

use App\Models\Contact;
use Illuminate\Http\UploadedFile;

/**
 * .claude/SECURITY.md #4 CSV validation caps, .claude/FRONTEND.md's
 * "upload -> column mapping -> validation preview -> confirm" wizard.
 */
/**
 * Verified immediately (markEmailVerified() — tests/Helpers.php) — the
 * real import (as opposed to the non-persisting preview step below) goes
 * through RequireSendingAccess, which now also gates on this (the "add
 * email verification" decision doc). Same reasoning seedCustomerAccount()
 * itself was updated for.
 */
function registerTenantAndToken(string $label): string
{
    $response = test()->postJson('/api/v1/register', [
        'name' => "{$label} Owner",
        'business_name' => "{$label} Co",
        'email' => strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    markEmailVerified($response->json('data.user.id'), $response->json('data.tenant.id'));

    return $response->json('data.token');
}

test('previewing a CSV neutralizes formula-injection payloads and flags them as warnings, not persisted', function () {
    $token = registerTenantAndToken('Preview Formula');

    $csv = "Name,Phone,Email\nSafe Row,555-0100,safe@example.com\n=cmd|'/c calc'!A1,555-0101,victim@example.com\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import/preview', ['file' => $file]);

    $response->assertOk();
    $rows = $response->json('data.rows');

    expect($rows[0]['data']['Name'])->toBe('Safe Row');
    expect($rows[0]['warnings'])->toBe([]);

    expect($rows[1]['data']['Name'])->toBe("'=cmd|'/c calc'!A1");
    expect($rows[1]['warnings'])->toContain('Name: neutralized a potential formula-injection payload');
    expect($rows[1]['valid'])->toBeTrue();

    // Preview must never persist anything.
    expect(Contact::count())->toBe(0);
});

test('previewing a CSV flags a cell exceeding the length cap as invalid', function () {
    $token = registerTenantAndToken('Preview Cell Cap');

    $tooLong = str_repeat('A', config('csv_import.max_cell_length') + 1);
    $csv = "Name,Phone,Email\n{$tooLong},555-0100,longcell@example.com\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import/preview', ['file' => $file]);

    $response->assertOk();
    $row = $response->json('data.rows')[0];

    expect($row['valid'])->toBeFalse();
    expect($row['errors'][0])->toContain('exceeds the '.config('csv_import.max_cell_length').'-character limit');
});

test('previewing a non-CSV binary file is rejected by mime validation', function () {
    $token = registerTenantAndToken('Preview Mime');

    // A real (tiny) PNG — genuinely binary content, not text pretending to
    // have a different extension.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $file = UploadedFile::fake()->createWithContent('contacts.png', $png);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import/preview', ['file' => $file]);

    $response->assertStatus(422);
    expect($response->json('fields.file'))->not->toBeNull();
});

test('previewing a CSV over the row cap flags row_cap_exceeded and stops at the cap', function () {
    config(['csv_import.max_rows' => 3]);
    $token = registerTenantAndToken('Preview Row Cap');

    $csv = "Name,Phone,Email\n";
    for ($i = 0; $i < 5; $i++) {
        $csv .= "Contact {$i},555-000{$i},contact{$i}@example.com\n";
    }
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import/preview', ['file' => $file]);

    $response->assertOk();
    expect($response->json('data.row_cap_exceeded'))->toBeTrue();
    expect($response->json('data.rows'))->toHaveCount(3);
});

test('importing persists only valid rows with formula-injection safely stored, scoped to the importing tenant', function () {
    $token = registerTenantAndToken('Import Persist');

    $tooLong = str_repeat('B', config('csv_import.max_cell_length') + 1);
    $csv = "Name,Phone,Email\n"
        ."Alice Safe,555-0100,alice@example.com\n"
        ."=SUM(A1:A9),555-0101,formula@example.com\n"
        ."{$tooLong},555-0102,toolong@example.com\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import', [
            'file' => $file,
            'mapping' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email'],
        ]);

    $response->assertCreated();
    expect($response->json('data.imported'))->toBe(2);
    expect($response->json('data.skipped'))->toBe(1);

    $names = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/contacts')
        ->json('data.data.*.name');

    expect($names)->toContain('Alice Safe');
    expect($names)->toContain("'=SUM(A1:A9)");
    expect($names)->not->toContain($tooLong);
});

test('a tenant can never see another tenant\'s imported contacts', function () {
    $tokenA = registerTenantAndToken('Isolation A');
    $tokenB = registerTenantAndToken('Isolation B');

    $csv = "Name,Phone,Email\nTenant A Contact,555-0100,a@example.com\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->post('/api/v1/contacts/import', [
            'file' => $file,
            'mapping' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email'],
        ])->assertCreated();

    $tenantBContacts = $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/contacts')
        ->json('data.data');

    expect($tenantBContacts)->toBe([]);
});
