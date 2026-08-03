<?php

function registerTestAccount(string $email = 'bob@example.com', string $password = 'correct-horse-battery-staple'): void
{
    test()->postJson('/api/v1/register', [
        'name' => 'Bob Owner',
        'business_name' => 'Bob Co',
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertCreated();
}

test('logging in with correct credentials returns a token', function () {
    registerTestAccount();

    $response = $this->postJson('/api/v1/login', [
        'email' => 'bob@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertOk();
    expect($response->json('data.token'))->toBeString();
    expect($response->json('data.user.email'))->toBe('bob@example.com');
});

test('logging in with the wrong password is rejected with 401, not 422', function () {
    registerTestAccount();

    $response = $this->postJson('/api/v1/login', [
        'email' => 'bob@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized();
});

test('logging in with an email that does not exist gives the same response as a wrong password', function () {
    $response = $this->postJson('/api/v1/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever',
    ]);

    $response->assertUnauthorized();
    expect($response->json('message'))->toBe('These credentials do not match our records.');
});

test('missing login fields are rejected', function () {
    $response = $this->postJson('/api/v1/login', []);

    $response->assertStatus(422);
    expect($response->json('fields'))->toHaveKeys(['email', 'password']);
});
