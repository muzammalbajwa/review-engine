<?php

use App\Models\GbpConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gbp\GbpConnectionRevokedException;
use App\Services\Gbp\GbpTokenRefresher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function seedGbpConnection(string $label, array $overrides = []): GbpConnection
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
            'oauth_token' => 'old-access-token',
            'refresh_token' => 'a-refresh-token',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/1',
            'status' => 'connected',
        ], $overrides));
        $connection->tenant_id = $tenantId;
        $connection->save();

        return $connection;
    });
}

test('a token that is not near expiry is not refreshed', function () {
    Http::fake();

    $connection = seedGbpConnection('Fresh Token', ['token_expires_at' => now()->addHour()]);

    app(GbpTokenRefresher::class)->refreshIfNeeded($connection);

    Http::assertNothingSent();
});

test('an expired token is refreshed via the refresh_token grant', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-access-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $connection = seedGbpConnection('Expired Token', ['token_expires_at' => now()->subMinute()]);

    $refreshed = app(GbpTokenRefresher::class)->refreshIfNeeded($connection);

    expect($refreshed->oauth_token)->toBe('new-access-token');
    expect($refreshed->token_expires_at->isFuture())->toBeTrue();

    Http::assertSent(function ($request) use ($connection) {
        return $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['refresh_token'] === $connection->refresh_token
            && $request['grant_type'] === 'refresh_token';
    });
});

test('a token expiring within the buffer window is refreshed proactively', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-access-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    // 2 minutes from now — inside the 5-minute buffer, not yet technically expired.
    $connection = seedGbpConnection('Soon Expiring', ['token_expires_at' => now()->addMinutes(2)]);

    app(GbpTokenRefresher::class)->refreshIfNeeded($connection);

    Http::assertSentCount(1);
});

test('an invalid_grant response marks the connection revoked and throws', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $connection = seedGbpConnection('Revoked Token', ['token_expires_at' => now()->subMinute()]);

    expect(fn () => app(GbpTokenRefresher::class)->refreshIfNeeded($connection))
        ->toThrow(GbpConnectionRevokedException::class);

    $connection->refresh();
    expect($connection->status)->toBe('revoked');
});

test('a non-invalid_grant failure bubbles up without marking the connection revoked', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'server_error'], 500),
    ]);

    $connection = seedGbpConnection('Transient Failure', ['token_expires_at' => now()->subMinute()]);

    expect(fn () => app(GbpTokenRefresher::class)->refreshIfNeeded($connection))
        ->toThrow(\Illuminate\Http\Client\RequestException::class);

    $connection->refresh();
    expect($connection->status)->toBe('connected');
});
