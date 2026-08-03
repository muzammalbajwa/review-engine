<?php

/**
 * .claude/SECURITY.md #3: "Rate-limit login (throttle:5,1) and all public
 * endpoints." throttle:5,1 keys by IP, not by the specific email attempted,
 * so this fires regardless of whether the credentials are for a real user.
 */
test('login rate limit fires after 5 attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        expect($response->status())->not->toBe(429);
    }

    $response = $this->postJson('/api/v1/login', [
        'email' => 'nobody@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429);
    expect($response->json('error'))->toBe('rate_limited');
});
