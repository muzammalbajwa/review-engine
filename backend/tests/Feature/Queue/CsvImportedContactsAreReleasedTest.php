<?php

use App\Jobs\SendReviewRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

/**
 * The audit's item 5: CsvImportService doesn't call ContactEnrollmentService
 * (it has its own inline creation loop, for bulk-transaction reasons) — so
 * this verifies rather than assumes that a CSV-imported contact is
 * genuinely indistinguishable to the release command: same status='pending'
 * on arrival, same release behavior, no separate code path required to
 * cover it.
 */
test('a CSV-imported contact is released by drip:release-pending exactly like any other pending contact', function () {
    [$token, $tenantId] = seedCustomerAccount('CSV Parity');
    setTenantBusinessHoursWideOpen($tenantId);

    $csv = "Name,Phone,Email\nCSV Contact,+15555550100,csvcontact@example.com\n";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $import = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/contacts/import', ['file' => $file, 'mapping' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email']])
        ->assertCreated();

    expect($import->json('data.imported'))->toBe(1);

    $contacts = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/contacts')->json('data.data');
    $csvContact = collect($contacts)->firstWhere('name', 'CSV Contact');

    expect($csvContact['status'])->toBe('pending');
    expect($csvContact['source'])->toBe('csv_import');

    Bus::fake([SendReviewRequest::class]);

    $this->artisan('drip:release-pending')->assertExitCode(0);

    Bus::assertDispatched(
        SendReviewRequest::class,
        fn (SendReviewRequest $job) => $job->contactId === $csvContact['id'] && $job->step === 1
    );
});
