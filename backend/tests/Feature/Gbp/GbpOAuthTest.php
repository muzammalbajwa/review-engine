<?php

use App\Models\GbpConnection;
use App\Services\Gbp\GoogleBusinessProfileClient;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * .claude/API.md: "GBP: GET /gbp/connect (OAuth start), GET /gbp/callback".
 * No real Google credentials exist (GOOGLE_CLIENT_ID/SECRET are blank) —
 * everything that would call the real Google API is faked via
 * Socialite::fake() and a mocked GoogleBusinessProfileClient. What's real
 * here: the state-parameter CSRF/tenant-binding logic, the RLS/encryption
 * writes, and every failure path's behavior.
 */
function registerAndGetToken(string $label): array
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

function fakeSocialiteUser(?string $refreshToken = '1//fake-refresh-token'): SocialiteUser
{
    return (new SocialiteUser())
        ->setToken('ya29.fake-access-token')
        ->setRefreshToken($refreshToken)
        ->setExpiresIn(3600);
}

test('connect requires authentication', function () {
    $this->getJson('/api/v1/gbp/connect')->assertUnauthorized();
});

test('connect returns a Google OAuth URL with the business.manage scope and a state parameter', function () {
    [$token] = registerAndGetToken('Connect Scopes');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/gbp/connect');

    $response->assertOk();
    $url = $response->json('data.redirect_url');

    expect($url)->toContain('accounts.google.com');
    expect($url)->toContain(urlencode('https://www.googleapis.com/auth/business.manage'));
    expect($url)->toContain('access_type=offline');
    expect($url)->toContain('prompt=consent');
    expect($url)->toMatch('/[?&]state=[^&]+/');
});

test('callback with no state is rejected without creating a connection', function () {
    $response = $this->get('/api/v1/gbp/callback');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('error=invalid_state');
    expect(GbpConnection::withoutGlobalScopes()->count())->toBe(0);
});

test('callback with a tampered state is rejected', function () {
    $response = $this->get('/api/v1/gbp/callback?state=not-a-real-encrypted-value&code=irrelevant');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('error=invalid_state');
});

test('a state value can only be consumed once — replay is rejected', function () {
    [$token, $tenantId] = registerAndGetToken('Replay State');

    $connectResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/connect');
    $url = $connectResponse->json('data.redirect_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $params);
    $state = $params['state'];

    Socialite::fake('google', fakeSocialiteUser());
    $this->mock(GoogleBusinessProfileClient::class, function ($mock) {
        $mock->shouldReceive('fetchPrimaryLocation')->andReturn([
            'location_id' => 'locations/1',
            'review_link' => 'https://g.page/r/x/review',
        ]);
    });

    $first = $this->get("/api/v1/gbp/callback?state={$state}&code=abc");
    expect($first->headers->get('Location'))->toContain('/gbp/connected');

    $second = $this->get("/api/v1/gbp/callback?state={$state}&code=abc");
    expect($second->headers->get('Location'))->toContain('error=invalid_state');
});

test('callback with no refresh token from Google is rejected and creates no connection', function () {
    [$token] = registerAndGetToken('No Refresh Token');

    $connectResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/connect');
    parse_str(parse_url($connectResponse->json('data.redirect_url'), PHP_URL_QUERY), $params);

    Socialite::fake('google', fakeSocialiteUser(refreshToken: null));

    $response = $this->get("/api/v1/gbp/callback?state={$params['state']}&code=abc");

    expect($response->headers->get('Location'))->toContain('error=no_refresh_token');
    expect(GbpConnection::withoutGlobalScopes()->count())->toBe(0);
});

test('callback when Google Business Profile has no location is rejected and creates no connection', function () {
    [$token] = registerAndGetToken('No Location');

    $connectResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/connect');
    parse_str(parse_url($connectResponse->json('data.redirect_url'), PHP_URL_QUERY), $params);

    Socialite::fake('google', fakeSocialiteUser());
    $this->mock(GoogleBusinessProfileClient::class, function ($mock) {
        $mock->shouldReceive('fetchPrimaryLocation')->andThrow(new RuntimeException('no locations'));
    });

    $response = $this->get("/api/v1/gbp/callback?state={$params['state']}&code=abc");

    expect($response->headers->get('Location'))->toContain('error=no_location_found');
    expect(GbpConnection::withoutGlobalScopes()->count())->toBe(0);
});

test('a successful callback creates an encrypted, tenant-scoped connection and redirects to the success page', function () {
    [$token, $tenantId] = registerAndGetToken('Successful Connect');

    $connectResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/gbp/connect');
    parse_str(parse_url($connectResponse->json('data.redirect_url'), PHP_URL_QUERY), $params);

    Socialite::fake('google', fakeSocialiteUser());
    $this->mock(GoogleBusinessProfileClient::class, function ($mock) {
        $mock->shouldReceive('fetchPrimaryLocation')->andReturn([
            'location_id' => 'locations/999',
            'review_link' => 'https://g.page/r/success/review',
        ]);
    });

    $response = $this->get("/api/v1/gbp/callback?state={$params['state']}&code=abc");

    expect($response->headers->get('Location'))->toContain('/gbp/connected');

    // TestCase::call() resets app.current_tenant_id to the nil sentinel
    // after every simulated request (see its docblock) — RLS applies
    // regardless of withoutGlobalScopes(), so reading back what the
    // callback just wrote needs the tenant context re-established first,
    // same as TokenEncryptionTest does.
    $connection = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return GbpConnection::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    });
    expect($connection)->not->toBeNull();
    expect($connection->status)->toBe('connected');
    expect($connection->location_id)->toBe('locations/999');
    expect($connection->oauth_token)->toBe('ya29.fake-access-token');
    expect($connection->refresh_token)->toBe('1//fake-refresh-token');
});
