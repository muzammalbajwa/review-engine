<?php

test('logging out invalidates the token so it can no longer be used', function () {
    $register = $this->postJson('/api/v1/register', [
        'name' => 'Cara Owner',
        'business_name' => 'Cara Co',
        'email' => 'cara@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    $token = $register->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/logout')
        ->assertOk();

    // The same token must no longer work.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/logout')
        ->assertUnauthorized();
});

test('logout requires authentication', function () {
    $this->postJson('/api/v1/logout')->assertUnauthorized();
});
