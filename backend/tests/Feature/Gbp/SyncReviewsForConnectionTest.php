<?php

use App\Jobs\SyncReviewsForConnection;
use App\Models\GbpConnection;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gbp\GoogleReviewsClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * .claude/ROADMAP.md Phase 3: "review sync... stores them tenant-scoped,
 * and flags new reviews needing a reply." QUEUE_CONNECTION=sync in
 * .env.testing, so SyncReviewsForConnection::dispatch() below runs the job
 * synchronously, inline — the real dispatch path, not a hand-rolled
 * shortcut.
 */
function seedConnectionForSync(string $label, array $overrides = []): GbpConnection
{
    return DB::transaction(function () use ($label, $overrides) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => $label, 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => Str::lower(Str::slug($label)).'-'.uniqid().'@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        $connection = new GbpConnection(array_merge([
            'oauth_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/1',
            'status' => 'connected',
        ], $overrides));
        $connection->tenant_id = $tenantId;
        $connection->save();

        return $connection;
    });
}

function reviewsForTenant(string $tenantId): \Illuminate\Support\Collection
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Review::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
    });
}

test('syncing stores new reviews tenant-scoped and flags them needing a reply', function () {
    $connection = seedConnectionForSync('Sync Basic');

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            [
                'google_review_id' => 'review-1',
                'rating' => 5,
                'text' => 'Great service!',
                'reviewer_name' => 'Alice',
                'review_created_at' => now()->toIso8601String(),
            ],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    $reviews = reviewsForTenant($connection->tenant_id);
    expect($reviews)->toHaveCount(1);
    expect($reviews[0]->google_review_id)->toBe('review-1');
    expect($reviews[0]->needs_reply)->toBeTrue();
    expect($reviews[0]->rating)->toBe(5);
});

test('running sync twice does not duplicate reviews', function () {
    $connection = seedConnectionForSync('Sync Idempotent');

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->twice()->andReturn([
            ['google_review_id' => 'review-dup', 'rating' => 4, 'text' => 'Good', 'reviewer_name' => 'Bob', 'review_created_at' => now()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);
    SyncReviewsForConnection::dispatch($connection->id);

    expect(reviewsForTenant($connection->tenant_id))->toHaveCount(1);
});

test('an already-replied review is not reset back to needing a reply on re-sync', function () {
    $connection = seedConnectionForSync('Sync Preserve Replied');

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'review-replied', 'rating' => 5, 'text' => 'Nice', 'reviewer_name' => 'Cara', 'review_created_at' => now()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    // Simulate Step 3 having already posted a reply.
    DB::transaction(function () use ($connection) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $connection->tenant_id]);
        Review::where('google_review_id', 'review-replied')->update(['needs_reply' => false]);
    });

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->once()->andReturn([
            ['google_review_id' => 'review-replied', 'rating' => 5, 'text' => 'Nice', 'reviewer_name' => 'Cara', 'review_created_at' => now()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connection->id);

    $review = reviewsForTenant($connection->tenant_id)->first();
    expect($review->needs_reply)->toBeFalse();
});

test('a revoked connection is detected, marked, and the sync stops cleanly without crashing', function () {
    $connection = seedConnectionForSync('Sync Revoked', ['token_expires_at' => now()->subMinute()]);

    \Illuminate\Support\Facades\Http::fake([
        'oauth2.googleapis.com/token' => \Illuminate\Support\Facades\Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldNotReceive('fetchReviews');
    });

    SyncReviewsForConnection::dispatch($connection->id);

    $connection->refresh();
    expect($connection->status)->toBe('revoked');
    expect(reviewsForTenant($connection->tenant_id))->toHaveCount(0);
});

test('a connection that is not connected is skipped entirely', function () {
    $connection = seedConnectionForSync('Sync Already Revoked', ['status' => 'revoked']);

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldNotReceive('fetchReviews');
    });

    SyncReviewsForConnection::dispatch($connection->id);

    expect(reviewsForTenant($connection->tenant_id))->toHaveCount(0);
});

test('two tenants sync independently with no cross-tenant leakage of reviews', function () {
    $connectionA = seedConnectionForSync('Sync Tenant A');
    $connectionB = seedConnectionForSync('Sync Tenant B');

    $this->mock(GoogleReviewsClient::class, function ($mock) {
        $mock->shouldReceive('fetchReviews')->twice()->andReturn([
            ['google_review_id' => 'shared-review-id', 'rating' => 3, 'text' => 'Ok', 'reviewer_name' => 'X', 'review_created_at' => now()->toIso8601String()],
        ]);
    });

    SyncReviewsForConnection::dispatch($connectionA->id);
    SyncReviewsForConnection::dispatch($connectionB->id);

    // Same google_review_id can independently exist for two tenants (the
    // unique constraint is per-tenant, not global) without colliding.
    expect(reviewsForTenant($connectionA->tenant_id))->toHaveCount(1);
    expect(reviewsForTenant($connectionB->tenant_id))->toHaveCount(1);

    DB::transaction(function () use ($connectionA) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $connectionA->tenant_id]);
        app(\App\Support\Tenancy\CurrentTenant::class)->set($connectionA->tenant_id);

        expect(Review::count())->toBe(1);

        app(\App\Support\Tenancy\CurrentTenant::class)->clear();
    });
});
