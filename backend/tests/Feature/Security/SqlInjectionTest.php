<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * .claude/TESTING.md: "SQL-injection tests: feed quotes/;/--/OR 1=1 into
 * every input; confirm parameterized handling, no error leakage." All DB
 * access in this app goes through Eloquent/Query Builder (SECURITY.md #1),
 * so these prove that guarantee holds under the classic payloads rather
 * than re-deriving it from reading the code.
 */
function sqliPayloads(): array
{
    return [
        "' OR '1'='1",
        "' OR 1=1 --",
        "'; DROP TABLE users; --",
        "admin'--",
        "' UNION SELECT * FROM users --",
        '" OR ""="',
    ];
}

test('registering with SQLi payloads in free-text fields stores them literally and never corrupts the schema', function () {
    foreach (sqliPayloads() as $i => $payload) {
        // register is throttle:5,1 (SECURITY.md #3) — that's a separate,
        // dedicated gate test. Flushing between payloads here keeps this
        // test purely about injection safety, not accidentally exercising
        // (and being blocked by) the rate limiter instead.
        Cache::flush();

        $response = $this->postJson('/api/v1/register', [
            'name' => $payload,
            'business_name' => $payload,
            'email' => "sqli-free-text-{$i}@example.com",
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Whatever the outcome, it must be a clean, well-formed JSON
        // response — never a raw DB error / stack trace leaking SQL
        // (SECURITY.md #4).
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        $response->assertCreated();

        // Stored as inert literal text, not executed — proves the insert
        // was parameterized, not string-concatenated.
        expect($response->json('data.user.name'))->toBe($payload);
    }

    // The classic "'; DROP TABLE users; --" attempt must not have touched
    // the schema at all.
    expect(Schema::hasTable('users'))->toBeTrue();
    expect(Schema::hasTable('tenants'))->toBeTrue();
});

test('registering with SQLi payloads in the email field is rejected by validation, not the database', function () {
    foreach (sqliPayloads() as $payload) {
        Cache::flush();

        $response = $this->postJson('/api/v1/register', [
            'name' => 'SQLi Email Test',
            'business_name' => 'SQLi Email Co',
            'email' => $payload,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Every payload here is not a syntactically valid email, so this
        // must be caught by the 'email' validation rule — a clean 422,
        // never a raw SQL error.
        $response->assertStatus(422);
        expect($response->json('fields.email'))->not->toBeNull();
    }
});

test('logging in with SQLi payloads never authenticates and never leaks a DB error', function () {
    // A real user to attack — if injection worked, this is who'd get logged
    // into without the right password.
    $this->postJson('/api/v1/register', [
        'name' => 'Victim',
        'business_name' => 'Victim Co',
        'email' => 'sqli-victim@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    foreach (sqliPayloads() as $payload) {
        // login is throttle:5,1 too — same reasoning as the register test.
        Cache::flush();

        // Payload in the email field.
        $response = $this->postJson('/api/v1/login', [
            'email' => $payload,
            'password' => 'irrelevant',
        ]);
        expect($response->status())->toBeIn([401, 422]);
        expect($response->json('data.token'))->toBeNull();

        Cache::flush();

        // Payload in the password field, against the real victim email —
        // the classic "OR 1=1" login-bypass shape.
        $response = $this->postJson('/api/v1/login', [
            'email' => 'sqli-victim@example.com',
            'password' => $payload,
        ]);
        $response->assertStatus(401);
        expect($response->json('data.token'))->toBeNull();
    }
});

// 'subscribing with a non-whitelisted interval value...' removed — POST
// /subscribe no longer exists (removed with the Lemon Squeezy package,
// see SubscriptionController's deletion). Re-add the equivalent
// SQLi-payload-in-a-whitelisted-field coverage once a Paddle-backed
// /subscribe exists again — same 'interval' Rule::in-against-config
// pattern this asserted (.claude/SECURITY.md #1), never a raw provider
// price/variant ID accepted from the client.
