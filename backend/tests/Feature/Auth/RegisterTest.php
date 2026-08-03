<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function validRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Alice Owner',
        'business_name' => 'Acme Roofing',
        'email' => 'alice@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ], $overrides);
}

test('registering creates a tenant and a user, and returns a token', function () {
    $response = $this->postJson('/api/v1/register', validRegisterPayload());

    $response->assertCreated();
    expect($response->json('data.token'))->toBeString();
    expect($response->json('data.user.email'))->toBe('alice@example.com');
    expect($response->json('data.user.role'))->toBe('owner');
    expect($response->json('data.user.is_admin'))->toBeFalse();
    expect($response->json('data.tenant.name'))->toBe('Acme Roofing');

    // Never leak the password, hashed or not.
    expect($response->json('data.user'))->not->toHaveKey('password');
});

test('the stored password is hashed, not plaintext', function () {
    $this->postJson('/api/v1/register', validRegisterPayload())->assertCreated();

    // Bypass RLS the same way login's lookup does, purely to inspect the
    // fixture in this test — not something application code does outside
    // the login/register flows.
    DB::statement("SELECT set_config('app.bypass_tenant_scope', 'true', true)");
    $user = User::where('email', 'alice@example.com')->first();

    expect($user->password)->not->toBe('correct-horse-battery-staple');
    expect(Hash::check('correct-horse-battery-staple', $user->password))->toBeTrue();
});

test('registering with an email that already exists is rejected', function () {
    $this->postJson('/api/v1/register', validRegisterPayload())->assertCreated();

    $response = $this->postJson('/api/v1/register', validRegisterPayload([
        'name' => 'Someone Else',
        'business_name' => 'A Different Co',
    ]));

    $response->assertStatus(422);
    expect($response->json('fields.email'))->not->toBeNull();

    // Only one tenant/user pair should exist, not two.
    DB::statement("SELECT set_config('app.bypass_tenant_scope', 'true', true)");
    expect(User::where('email', 'alice@example.com')->count())->toBe(1);
});

test('mismatched password confirmation is rejected', function () {
    $response = $this->postJson('/api/v1/register', validRegisterPayload([
        'password_confirmation' => 'something-else',
    ]));

    $response->assertStatus(422);
    expect($response->json('fields.password'))->not->toBeNull();
});

test('missing required fields are rejected', function () {
    $response = $this->postJson('/api/v1/register', []);

    $response->assertStatus(422);
    expect($response->json('fields'))->toHaveKeys(['name', 'business_name', 'email', 'password']);
});
