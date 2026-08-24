<?php

/**
 * .claude/ROADMAP.md/SECURITY.md #5: every webhook must verify its
 * signature on every call. routes/api.php applies Laravel\Paddle\Http\
 * Middleware\VerifyWebhookSignature unconditionally on the route itself
 * (not left to Cashier's own WebhookController constructor, which only
 * attaches it when cashier.webhook_secret happens to be truthy) — these
 * prove that actually holds against real requests, using Cashier's own
 * verification code, not a hand-rolled HMAC check (this task's explicit
 * requirement #3).
 *
 * Paddle's real scheme (developer.paddle.com/webhook-reference/
 * verifying-webhooks, verified against the installed middleware source):
 * Paddle-Signature: ts=<unix>;h1=<hex hmac-sha256 of "{ts}:{rawBody}">.
 */
test('webhook with no Paddle-Signature header is rejected', function () {
    $payload = json_encode(['event_type' => 'subscription.updated', 'data' => []]);

    $response = test()->call('POST', '/api/v1/paddle/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertStatus(403);
});

test('webhook with a badly-formed Paddle-Signature header is rejected', function () {
    $payload = json_encode(['event_type' => 'subscription.updated', 'data' => []]);

    $response = test()->call('POST', '/api/v1/paddle/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PADDLE_SIGNATURE' => 'not-even-the-right-shape',
    ], $payload);

    $response->assertStatus(403);
});

test('webhook signed with the wrong secret is rejected', function () {
    $payload = json_encode(['event_type' => 'subscription.updated', 'data' => []]);

    $response = postSignedPaddleWebhook(
        json_decode($payload, true),
        secret: 'totally_the_wrong_secret',
    );

    $response->assertStatus(403);
});

test('webhook with a stale timestamp outside the maximum variance is rejected', function () {
    // .env.testing sets PADDLE_WEBHOOK_SECRET=paddle_test_signing_secret_placeholder.
    $secret = config('cashier.webhook_secret');
    expect($secret)->not->toBeEmpty();

    $payload = json_encode(['event_type' => 'subscription.updated', 'data' => []]);
    // VerifyWebhookSignature's own maximumVariance is 5 seconds — well
    // outside it, correctly signed for that (wrong) timestamp.
    $signature = paddleSignatureHeader($payload, $secret, timestamp: time() - 3600);

    $response = test()->call('POST', '/api/v1/paddle/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PADDLE_SIGNATURE' => $signature,
    ], $payload);

    $response->assertStatus(403);
});

test('webhook correctly signed with the real secret passes verification', function () {
    // No customer_id and an unrecognized event name — clears signature
    // verification (the thing under test) and then legitimately no-ops
    // all the way through: PaddleWebhookController finds no customer_id
    // to resolve tenant context from, delegates with none, and Cashier's
    // own WebhookController finds no handler for a made-up event type.
    $response = postSignedPaddleWebhook([
        'event_type' => 'some.unhandled.event.type',
        'data' => ['id' => 'unknown_object_id'],
    ]);

    // Correctly signed, so it clears VerifyWebhookSignature. The
    // meaningful assertion is "not rejected for its signature", proving
    // the middleware verifies rather than blanket-denying everything.
    $response->assertOk();
});
