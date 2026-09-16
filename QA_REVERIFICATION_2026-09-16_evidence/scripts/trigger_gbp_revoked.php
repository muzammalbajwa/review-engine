<?php

use App\Models\GbpConnection;
use App\Models\User;
use App\Services\Gbp\GbpConnectionRevokedException;
use App\Services\Gbp\GbpTokenRefresher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$email = getenv('AUDIT_EMAIL');

$tenantId = DB::transaction(function () use ($email) {
    DB::statement("SELECT set_config('app.is_admin', 'true', true)");

    return User::withoutGlobalScopes()->where('email', $email)->value('tenant_id');
});

// Only Google's token endpoint is stubbed; queueing and delivery are real.
Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

DB::transaction(function () use ($tenantId) {
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

    $connection = GbpConnection::query()->firstOrNew(['tenant_id' => $tenantId]);
    $connection->tenant_id = $tenantId;
    $connection->oauth_token = 'audit-expired-token';
    $connection->refresh_token = 'audit-dead-refresh-token';
    $connection->token_expires_at = now()->subMinute();
    $connection->location_id = 'locations/audit-2026-09-16';
    $connection->status = 'connected';
    $connection->revoked_alert_sent_at = null;
    $connection->save();

    try {
        app(GbpTokenRefresher::class)->refreshIfNeeded($connection);
        echo "UNEXPECTED: no exception\n";
    } catch (GbpConnectionRevokedException) {
        echo "revoked, notification queued for tenant {$tenantId} (pid ".getmypid().")\n";
    }
});
