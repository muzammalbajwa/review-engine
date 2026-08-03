<?php

use App\Models\GbpConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * .claude/SECURITY.md #5: "Google OAuth tokens: encrypt at rest (Laravel
 * encrypted casts)." This doesn't take the `encrypted` cast on faith — it
 * reads the column back with the query builder (which does NOT apply
 * Eloquent casts), so it sees exactly what's actually sitting in Postgres.
 */
function seedTenantForGbp(string $name): string
{
    return DB::transaction(function () use ($name) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => $name, 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$name} Owner",
            'email' => Str::lower(Str::slug($name)).'-'.uniqid().'@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        return $tenantId;
    });
}

test('oauth_token and refresh_token are genuinely encrypted at rest, not just cast-labeled', function () {
    $tenantId = seedTenantForGbp('Encryption Proof');

    $plainAccessToken = 'ya29.REAL_LOOKING_ACCESS_TOKEN_'.Str::random(20);
    $plainRefreshToken = '1//REAL_LOOKING_REFRESH_TOKEN_'.Str::random(20);

    $connectionId = DB::transaction(function () use ($tenantId, $plainAccessToken, $plainRefreshToken) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $connection = new GbpConnection([
            'oauth_token' => $plainAccessToken,
            'refresh_token' => $plainRefreshToken,
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/12345',
            'review_link' => 'https://g.page/r/test/review',
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantId;
        $connection->save();

        return $connection->id;
    });

    // Raw query builder read — bypasses Eloquent's cast layer entirely, so
    // this is exactly what's physically stored in the column.
    $rawRow = DB::transaction(function () use ($tenantId, $connectionId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return DB::table('gbp_connections')->where('id', $connectionId)->first();
    });

    // 1. The raw bytes must not equal, or even contain, the plaintext.
    expect($rawRow->oauth_token)->not->toBe($plainAccessToken);
    expect($rawRow->oauth_token)->not->toContain($plainAccessToken);
    expect($rawRow->refresh_token)->not->toBe($plainRefreshToken);
    expect($rawRow->refresh_token)->not->toContain($plainRefreshToken);

    // 2. It's not merely obfuscated (e.g. base64) — it's real, keyed
    // encryption: decrypting with the app's actual key recovers the
    // plaintext exactly, and a stored ciphertext is longer than the
    // plaintext (IV + MAC + payload overhead), consistent with Laravel's
    // AES-256-CBC + HMAC scheme, not a reversible-without-a-key encoding.
    expect(Crypt::decryptString($rawRow->oauth_token))->toBe($plainAccessToken);
    expect(Crypt::decryptString($rawRow->refresh_token))->toBe($plainRefreshToken);
    expect(strlen($rawRow->oauth_token))->toBeGreaterThan(strlen($plainAccessToken));

    // 3. Round-tripping through Eloquent (the actual application code path)
    // transparently decrypts back to the original plaintext.
    $viaEloquent = DB::transaction(function () use ($tenantId, $connectionId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        app(CurrentTenant::class)->set($tenantId);

        try {
            return GbpConnection::findOrFail($connectionId);
        } finally {
            app(CurrentTenant::class)->clear();
        }
    });

    expect($viaEloquent->oauth_token)->toBe($plainAccessToken);
    expect($viaEloquent->refresh_token)->toBe($plainRefreshToken);
});

test('a tenant can never read another tenant\'s GBP tokens, even with the app-layer scope bypassed', function () {
    $tenantA = seedTenantForGbp('GBP Isolation A');
    $tenantB = seedTenantForGbp('GBP Isolation B');

    $connectionBId = DB::transaction(function () use ($tenantB) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantB]);

        $connection = new GbpConnection([
            'oauth_token' => 'tenant-b-secret-token',
            'refresh_token' => 'tenant-b-secret-refresh',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/b',
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantB;
        $connection->save();

        return $connection->id;
    });

    DB::transaction(function () use ($tenantA, $connectionBId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantA]);
        app(CurrentTenant::class)->set($tenantA);

        expect(GbpConnection::find($connectionBId))->toBeNull();
        expect(GbpConnection::withoutGlobalScopes()->find($connectionBId))->toBeNull();
        expect(DB::table('gbp_connections')->where('id', $connectionBId)->first())->toBeNull();

        app(CurrentTenant::class)->clear();
    });
});
