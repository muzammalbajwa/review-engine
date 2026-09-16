<?php

use App\Models\GbpConnection;
use Illuminate\Support\Facades\DB;

$tenantId = getenv('AUDIT_TENANT');
$status = getenv('AUDIT_GBP_STATUS');

DB::transaction(function () use ($tenantId, $status) {
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
    $c = GbpConnection::query()->first();
    $c->status = $status;
    $c->save();
    echo "GBP connection for {$tenantId} is now {$c->status}\n";
});
