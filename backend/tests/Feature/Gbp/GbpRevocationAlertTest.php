<?php

use App\Models\GbpConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\GbpConnectionRevoked;
use App\Services\Gbp\GbpConnectionRevokedException;
use App\Services\Gbp\GbpTokenRefresher;
use App\Services\Gbp\GoogleBusinessProfileClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * .claude/QUEUE.md: the tenant-facing counterpart to
 * GbpConnectionRevokedException. Idempotent via
 * gbp_connections.revoked_alert_sent_at.
 */
function seedOwnerAndConnection(string $label, array $connectionOverrides = []): array
{
    $tenantId = (string) Str::uuid();
    $email = strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com';

    $connection = DB::transaction(function () use ($tenantId, $label, $email, $connectionOverrides) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => "{$label} Co", 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => $email,
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        $connection = new GbpConnection(array_merge([
            'oauth_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->subMinute(),
            'location_id' => 'locations/1',
            'status' => 'connected',
        ], $connectionOverrides));
        $connection->tenant_id = $tenantId;
        $connection->save();

        return $connection;
    });

    return [$tenantId, $email, $connection];
}

test('a connection transitioning to revoked sends exactly one alert email to the tenant owner', function () {
    [$tenantId, $email, $connection] = seedOwnerAndConnection('First Revoke');

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    Notification::fake();

    expect(fn () => app(GbpTokenRefresher::class)->refreshIfNeeded($connection))
        ->toThrow(GbpConnectionRevokedException::class);

    $connection->refresh();
    expect($connection->status)->toBe('revoked');
    expect($connection->revoked_alert_sent_at)->not->toBeNull();

    Notification::assertSentTo(
        User::withoutGlobalScopes()->where('email', $email)->first(),
        GbpConnectionRevoked::class
    );
});

test('a connection already revoked and already alerted does not send a second email on the next failed refresh', function () {
    [$tenantId, $email, $connection] = seedOwnerAndConnection('Already Alerted', [
        'status' => 'revoked',
        'revoked_alert_sent_at' => now()->subHour(),
    ]);
    $firstAlertTime = $connection->revoked_alert_sent_at;

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    Notification::fake();

    expect(fn () => app(GbpTokenRefresher::class)->refreshIfNeeded($connection))
        ->toThrow(GbpConnectionRevokedException::class);

    $connection->refresh();
    expect($connection->status)->toBe('revoked');
    expect($connection->revoked_alert_sent_at)->toEqual($firstAlertTime);

    Notification::assertNothingSent();
});

test('reconnecting resets the alert marker so a future revocation alerts again', function () {
    [$tenantId, $email, $connection] = seedOwnerAndConnection('Reconnect Resets', [
        'status' => 'revoked',
        'revoked_alert_sent_at' => now()->subDay(),
    ]);

    $login = test()->postJson('/api/v1/login', ['email' => $email, 'password' => 'correct-horse-battery-staple'])
        ->assertOk();
    $token = $login->json('data.token');

    $connectResponse = test()->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/connect');
    parse_str(parse_url($connectResponse->json('data.redirect_url'), PHP_URL_QUERY), $params);

    Socialite::fake('google', (new SocialiteUser)
        ->setToken('ya29.fresh-access-token')
        ->setRefreshToken('1//fresh-refresh-token')
        ->setExpiresIn(3600));
    test()->mock(GoogleBusinessProfileClient::class, function ($mock) {
        $mock->shouldReceive('fetchPrimaryLocation')->andReturn([
            'location_id' => 'locations/1',
            'review_link' => 'https://g.page/r/reset/review',
        ]);
    });

    $callback = test()->get("/api/v1/gbp/callback?state={$params['state']}&code=abc");
    expect($callback->headers->get('Location'))->toContain('/gbp/connected');

    $reconnected = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return GbpConnection::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    });
    expect($reconnected->status)->toBe('connected');
    expect($reconnected->revoked_alert_sent_at)->toBeNull();

    // Now revoke it again — this should alert, because reconnecting cleared
    // the marker left over from the earlier revocation.
    $reconnected->token_expires_at = now()->subMinute();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    Notification::fake();

    // TestCase::call() (invoked by the login/connect/callback requests
    // above) resets app.current_tenant_id to the nil sentinel after every
    // simulated request — markRevoked()'s internal User lookup needs the
    // real tenant context re-established first, same as reading back any
    // other previous request's write in this suite.
    DB::transaction(function () use ($tenantId, $reconnected) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        expect(fn () => app(GbpTokenRefresher::class)->refreshIfNeeded($reconnected))
            ->toThrow(GbpConnectionRevokedException::class);
    });

    Notification::assertSentTo(
        User::withoutGlobalScopes()->where('email', $email)->first(),
        GbpConnectionRevoked::class
    );
});
