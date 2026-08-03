<?php

use App\Models\GbpConnection;
use App\Models\Reply;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * .claude/ROADMAP.md Phase 3 Step 3: "Claude-generated replies with delayed
 * posting... Surface PolicyViolation on rejected replies." No real Google or
 * Anthropic credentials exist — both are faked via Http::fake(); what's real
 * here is the drafting/posting orchestration, the policy-violation surfacing
 * (never hidden), the revoked-connection guard, and tenant isolation.
 */
function registerAndGetTokenForReply(string $label): array
{
    $response = test()->postJson('/api/v1/register', [
        'name' => "{$label} Owner",
        'business_name' => "{$label} Co",
        'email' => strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    return [$response->json('data.token'), $response->json('data.tenant.id')];
}

function seedGbpConnectionForReply(string $tenantId, array $overrides = []): GbpConnection
{
    return DB::transaction(function () use ($tenantId, $overrides) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

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

function seedReviewForReply(string $tenantId, int $gbpConnectionId, array $overrides = []): Review
{
    return DB::transaction(function () use ($tenantId, $gbpConnectionId, $overrides) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $review = new Review(array_merge([
            'gbp_connection_id' => $gbpConnectionId,
            'google_review_id' => 'review-'.uniqid(),
            'rating' => 2,
            'text' => 'The job took much longer than promised.',
            'reviewer_name' => 'Dana',
            'review_created_at' => now(),
            'needs_reply' => true,
            'synced_at' => now(),
        ], $overrides));
        $review->tenant_id = $tenantId;
        $review->save();

        return $review;
    });
}

test('a drafted reply is posted to Google and the review no longer needs a reply', function () {
    [$token, $tenantId] = registerAndGetTokenForReply('Reply Success');
    $connection = seedGbpConnectionForReply($tenantId);
    $review = seedReviewForReply($tenantId, $connection->id);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Thank you for your feedback — we are sorry the job ran long.']]], 200),
        'mybusiness.googleapis.com/*' => Http::response([], 200),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/reviews/{$review->id}/reply");

    $response->assertCreated();
    expect($response->json('data.body'))->toBe('Thank you for your feedback — we are sorry the job ran long.');
    expect($response->json('data.policy_violation'))->toBeFalse();
    expect($response->json('data.posted_at'))->not->toBeNull();

    $fresh = DB::transaction(function () use ($tenantId, $review) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Review::withoutGlobalScopes()->find($review->id);
    });
    expect($fresh->needs_reply)->toBeFalse();
});

test('a policy-violating reply is surfaced with Google\'s reason, not hidden, and the review still needs a reply', function () {
    [$token, $tenantId] = registerAndGetTokenForReply('Reply Policy Violation');
    $connection = seedGbpConnectionForReply($tenantId);
    $review = seedReviewForReply($tenantId, $connection->id);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Please visit our store for a free gift!']]], 200),
        'mybusiness.googleapis.com/*' => Http::response(['error' => ['message' => 'Reply content violates promotional content policy.']], 400),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/reviews/{$review->id}/reply");

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('policy_violation');
    expect($response->json('message'))->toBe('Reply content violates promotional content policy.');

    $reply = DB::transaction(function () use ($tenantId, $review) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Reply::withoutGlobalScopes()->where('review_id', $review->id)->first();
    });
    expect($reply)->not->toBeNull();
    expect($reply->policy_violation)->toBeTrue();
    expect($reply->policy_violation_reason)->toBe('Reply content violates promotional content policy.');
    expect($reply->posted_at)->toBeNull();

    $freshReview = DB::transaction(function () use ($tenantId, $review) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Review::withoutGlobalScopes()->find($review->id);
    });
    expect($freshReview->needs_reply)->toBeTrue();
});

test('replying against an already-revoked GBP connection is rejected without calling Google or Claude', function () {
    [$token, $tenantId] = registerAndGetTokenForReply('Reply Revoked');
    $connection = seedGbpConnectionForReply($tenantId, ['status' => 'revoked']);
    $review = seedReviewForReply($tenantId, $connection->id);

    Http::fake();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/reviews/{$review->id}/reply");

    $response->assertStatus(409);
    expect($response->json('error'))->toBe('gbp_connection_revoked');
    Http::assertNothingSent();
});

test('a token revoked at Google mid-reply is detected and surfaced, not left as a silent failure', function () {
    [$token, $tenantId] = registerAndGetTokenForReply('Reply Revoked Mid Flow');
    $connection = seedGbpConnectionForReply($tenantId, ['token_expires_at' => now()->subMinute()]);
    $review = seedReviewForReply($tenantId, $connection->id);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/reviews/{$review->id}/reply");

    $response->assertStatus(409);
    expect($response->json('error'))->toBe('gbp_connection_revoked');

    $freshConnection = DB::transaction(function () use ($tenantId, $connection) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return GbpConnection::withoutGlobalScopes()->find($connection->id);
    });
    expect($freshConnection->status)->toBe('revoked');
});

test('a tenant can never reply to another tenant\'s review', function () {
    [, $tenantAId] = registerAndGetTokenForReply('Reply Isolation A');
    [$tokenB] = registerAndGetTokenForReply('Reply Isolation B');

    $connectionA = seedGbpConnectionForReply($tenantAId);
    $reviewA = seedReviewForReply($tenantAId, $connectionA->id);

    Http::fake();

    $response = $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->postJson("/api/v1/reviews/{$reviewA->id}/reply");

    $response->assertNotFound();
    Http::assertNothingSent();
});

test('GET /reviews lists only the authenticated tenant\'s reviews and supports the needs_reply filter', function () {
    [$tokenA, $tenantAId] = registerAndGetTokenForReply('List Reviews A');
    [$tokenB, $tenantBId] = registerAndGetTokenForReply('List Reviews B');

    $connectionA = seedGbpConnectionForReply($tenantAId);
    seedReviewForReply($tenantAId, $connectionA->id, ['needs_reply' => true]);
    seedReviewForReply($tenantAId, $connectionA->id, ['needs_reply' => false]);

    $connectionB = seedGbpConnectionForReply($tenantBId);
    seedReviewForReply($tenantBId, $connectionB->id);

    $all = $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/reviews');
    $all->assertOk();
    expect($all->json('data.total'))->toBe(2);

    $needingReply = $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/reviews?needs_reply=1');
    $needingReply->assertOk();
    expect($needingReply->json('data.total'))->toBe(1);
});
