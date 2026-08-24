<?php

use App\Jobs\SendReviewRequest;
use App\Jobs\SyncReviewsForConnection;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\GbpConnection;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gbp\GoogleReviewsClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * SyncReviewsForConnection::attemptMatchReviewToContact()'s own docblock
 * spells out exactly what this heuristic is and isn't: Google's review
 * payload carries a display name and a timestamp, never a customer id, so
 * this is a best-effort name+timing-window match, not a guarantee. These
 * tests exercise both what it correctly catches and — just as
 * importantly — what it correctly refuses to guess at.
 */
function seedConnectionAndContactForMatching(string $label, string $contactName, string $sentAt): array
{
    return DB::transaction(function () use ($label, $contactName, $sentAt) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => "{$label} Co", 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => Str::lower(Str::slug($label)).'-'.Str::random(6).'@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        $connection = new GbpConnection([
            'oauth_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/matching-test',
            'review_link' => 'https://g.page/r/matching-test/review',
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantId;
        $connection->save();

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        $contact = new Contact([
            'campaign_id' => $campaign->id,
            'name' => $contactName,
            'email' => Str::lower(Str::slug($label)).'-customer@example.com',
            'status' => 'sent',
        ]);
        $contact->tenant_id = $tenantId;
        $contact->consent_at = now();
        $contact->source = 'quick_add';
        $contact->save();

        $message = new Message([
            'contact_id' => $contact->id,
            'step' => 1,
            'status' => 'sent',
            'sent_at' => $sentAt,
        ]);
        $message->tenant_id = $tenantId;
        $message->save();

        return [$connection, $contact, $message];
    });
}

function messageAfterSync(string $tenantId, int $messageId): Message
{
    return DB::transaction(function () use ($tenantId, $messageId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Message::find($messageId);
    });
}

test('an exact reviewer name match sets reviewed_at to the review timestamp', function () {
    [$connection, , $message] = seedConnectionAndContactForMatching('Match Exact', 'Priya Shah', now()->subDays(3));
    $reviewCreatedAt = now()->subDay()->toIso8601String();

    $this->mock(GoogleReviewsClient::class, function ($mock) use ($reviewCreatedAt) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-exact', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priya Shah', 'review_created_at' => $reviewCreatedAt],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    $updated = messageAfterSync($connection->tenant_id, $message->id);
    expect($updated->reviewed_at)->not->toBeNull();
    expect($updated->reviewed_at->toIso8601String())->toBe(now()->parse($reviewCreatedAt)->toIso8601String());
});

test('Google\'s common "First L." display format matches a contact\'s full name', function () {
    [$connection, , $message] = seedConnectionAndContactForMatching('Match Initial', 'Priya Shah', now()->subDays(3));

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-initial', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priya S.', 'review_created_at' => now()->subDay()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    expect(messageAfterSync($connection->tenant_id, $message->id)->reviewed_at)->not->toBeNull();
});

test('a single-token contact name matches on first name alone', function () {
    [$connection, , $message] = seedConnectionAndContactForMatching('Match SingleToken', 'Priya', now()->subDays(3));

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-single', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priya Shah', 'review_created_at' => now()->subDay()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    expect(messageAfterSync($connection->tenant_id, $message->id)->reviewed_at)->not->toBeNull();
});

test('a different first name never matches, no matter how similar the surname', function () {
    [$connection, , $message] = seedConnectionAndContactForMatching('Match WrongFirst', 'Priya Shah', now()->subDays(3));

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-wrong-first', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priyanka Shah', 'review_created_at' => now()->subDay()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    expect(messageAfterSync($connection->tenant_id, $message->id)->reviewed_at)->toBeNull();
});

test('two different candidate contacts with the same matching name -> ambiguous, neither is touched', function () {
    [$connection, , $messageA] = seedConnectionAndContactForMatching('Match Ambiguous A', 'Priya Shah', now()->subDays(3));

    // A second contact, same tenant/connection, same plausibly-matching
    // name, also inside the timing window.
    $contactB = DB::transaction(function () use ($connection) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $connection->tenant_id]);

        $campaign = Campaign::findOrCreateDefault();

        $contact = new Contact([
            'campaign_id' => $campaign->id,
            'name' => 'Priya Shah',
            'email' => 'priya-shah-b@example.com',
            'status' => 'sent',
        ]);
        $contact->tenant_id = $connection->tenant_id;
        $contact->consent_at = now();
        $contact->source = 'quick_add';
        $contact->save();

        $message = new Message([
            'contact_id' => $contact->id,
            'step' => 1,
            'status' => 'sent',
            'sent_at' => now()->subDays(2),
        ]);
        $message->tenant_id = $connection->tenant_id;
        $message->save();

        return $contact;
    });

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-ambiguous', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priya Shah', 'review_created_at' => now()->subDay()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    expect(messageAfterSync($connection->tenant_id, $messageA->id)->reviewed_at)->toBeNull();
    $messageB = DB::transaction(function () use ($connection, $contactB) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $connection->tenant_id]);

        return Message::where('contact_id', $contactB->id)->first();
    });
    expect($messageB->reviewed_at)->toBeNull();
});

test('a review left long before the matching window (180 days) is not matched', function () {
    [$connection, , $message] = seedConnectionAndContactForMatching('Match StaleWindow', 'Priya Shah', now()->subDays(200));

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-stale', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priya Shah', 'review_created_at' => now()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    expect(messageAfterSync($connection->tenant_id, $message->id)->reviewed_at)->toBeNull();
});

test('a review dated before the message was even sent is not matched', function () {
    [$connection, , $message] = seedConnectionAndContactForMatching('Match BeforeSend', 'Priya Shah', now());

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-before-send', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priya Shah', 'review_created_at' => now()->subDay()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    expect(messageAfterSync($connection->tenant_id, $message->id)->reviewed_at)->toBeNull();
});

/**
 * The point of reviewed_at existing at all: once a matched review sets
 * it, SendReviewRequest's own suppression check must actually no-op the
 * next step for real — not asserted indirectly, run for real and shown.
 */
test('a matched review actually suppresses the contact\'s next-step send — the real job, not an assumption', function () {
    [$connection, $contact, $message] = seedConnectionAndContactForMatching('Match Suppression', 'Priya Shah', now()->subDays(3));

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'r-suppress', 'rating' => 5, 'text' => 'Great!', 'reviewer_name' => 'Priya Shah', 'review_created_at' => now()->subDay()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    $matched = messageAfterSync($connection->tenant_id, $message->id);
    expect($matched->reviewed_at)->not->toBeNull();

    // Now run the REAL step-2 job for this contact, no fakes on
    // SendReviewRequest itself — the actual handle() method, the actual
    // suppression check reading the actual reviewed_at column.
    Mail::fake();
    Bus::fake([SendReviewRequest::class]); // only fakes any *further* dispatch this job might attempt

    (new SendReviewRequest($contact->id, 2))->handle();

    Mail::assertNothingSent();
    Bus::assertNotDispatched(SendReviewRequest::class);
});
