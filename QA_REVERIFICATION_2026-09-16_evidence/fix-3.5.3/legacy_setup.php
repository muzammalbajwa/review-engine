<?php

use App\Models\GbpConnection;
use App\Models\SenderIdentity;
use App\Models\TimingRule;
use App\Services\Contacts\ContactEnrollmentService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

$tenantId = getenv('AUDIT_TENANT');

$out = DB::transaction(function () use ($tenantId) {
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
    app(CurrentTenant::class)->set($tenantId);

    $rule = TimingRule::query()->first();

    if (! SenderIdentity::query()->where('verified', true)->exists()) {
        $identity = new SenderIdentity(['from_name' => 'Reverify Roofing', 'from_email' => 'hello@reverify-roofing.test', 'verified' => true]);
        $identity->tenant_id = $tenantId;
        $identity->save();
    }

    $connection = GbpConnection::query()->first();
    $connection->status = 'connected';
    $connection->review_link = 'https://g.page/r/reverify-audit/review';
    $connection->token_expires_at = now()->addDays(30);
    $connection->save();

    $svc = app(ContactEnrollmentService::class);
    $stamp = now()->format('His');
    $a = $svc->create('Legacy Before Contact', null, "legacy-before-{$stamp}@example.com", 'audit');
    $b = $svc->create('Legacy After Contact', null, "legacy-after-{$stamp}@example.com", 'audit');

    app(CurrentTenant::class)->clear();

    return [
        'tenant' => $tenantId,
        'hours' => [$rule->business_hours_start, $rule->business_hours_end, $rule->timezone],
        'contact_before' => ['id' => $a->id, 'email' => $a->email],
        'contact_after' => ['id' => $b->id, 'email' => $b->email],
    ];
});

file_put_contents(getenv('AUDIT_OUT'), json_encode($out, JSON_PRETTY_PRINT));
echo json_encode($out), "\n";
