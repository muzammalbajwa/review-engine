<?php

/**
 * .claude/ROADMAP.md Phase 1: "webhook (signature-verified)".
 * .claude/SECURITY.md #5: "Stripe webhook: verify the signature on every
 * call." StripeWebhookController applies VerifyWebhookSignature
 * unconditionally (see its docblock) — these prove that actually holds
 * against real requests, not just that the middleware is attached.
 */
function stripeSignatureHeader(string $payload, string $secret, int $timestamp): string
{
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$signature}";
}

test('webhook with no Stripe-Signature header is rejected', function () {
    $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]]);

    $response = $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    expect($response->status())->toBeIn([400, 403]);
});

test('webhook with a badly-signed payload is rejected', function () {
    $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]]);

    $response = $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=0000000000000000000000000000000000000000000000000000000000000000',
    ], $payload);

    expect($response->status())->toBe(403);
});

test('webhook signed with the wrong secret is rejected', function () {
    $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]]);
    $header = stripeSignatureHeader($payload, 'whsec_totally_the_wrong_secret', time());

    $response = $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $header,
    ], $payload);

    expect($response->status())->toBe(403);
});

test('webhook correctly signed with the real secret passes verification', function () {
    // .env.testing sets STRIPE_WEBHOOK_SECRET=whsec_test_placeholder.
    $secret = config('cashier.webhook.secret');
    expect($secret)->not->toBeEmpty();

    $payload = json_encode(['type' => 'some.unhandled.event.type', 'data' => ['object' => []]]);
    $header = stripeSignatureHeader($payload, $secret, time());

    $response = $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $header,
    ], $payload);

    // Correctly signed, so it clears VerifyWebhookSignature; Cashier's
    // WebhookController has no handler for this made-up event type, so it
    // falls through to missingMethod() — an empty 200. The meaningful
    // assertion is "not rejected for its signature", proving the middleware
    // verifies rather than blanket-denying everything.
    $response->assertOk();
});
