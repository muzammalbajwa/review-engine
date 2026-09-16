<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$email = getenv('AUDIT_EMAIL');

DB::transaction(function () use ($email) {
    DB::statement("SELECT set_config('app.is_admin', 'true', true)");
    $user = User::withoutGlobalScopes()->where('email', $email)->firstOrFail();

    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $user->tenant_id]);

    $subscription = new Subscription([
        'billable_id' => $user->id,
        'billable_type' => User::class,
        'type' => 'default',
        'paddle_id' => 'sub_audit'.Str::random(10),
        'status' => Subscription::STATUS_ACTIVE,
        'renews_at' => today()->addDays(10)->setTime(14, 0),
        'ends_at' => null,
    ]);
    $subscription->tenant_id = $user->tenant_id;
    $subscription->save();

    echo "seeded subscription {$subscription->id} renewing ".$subscription->renews_at." for {$email}\n";
});
