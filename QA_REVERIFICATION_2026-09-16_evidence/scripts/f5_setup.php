<?php

use App\Models\TimingRule;
use App\Models\User;
use App\Services\Contacts\ContactEnrollmentService;
use Illuminate\Support\Facades\DB;

$tenantFor = function (string $email): string {
    return DB::transaction(function () use ($email) {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return User::withoutGlobalScopes()->where('email', $email)->value('tenant_id');
    });
};

$inTenant = function (string $tenantId, callable $fn) {
    return DB::transaction(function () use ($tenantId, $fn) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        app(\App\Support\Tenancy\CurrentTenant::class)->set($tenantId);
        try {
            return $fn();
        } finally {
            app(\App\Support\Tenancy\CurrentTenant::class)->clear();
        }
    });
};

$openTenant = $tenantFor(getenv('AUDIT_OPEN_EMAIL'));
$defaultTenant = $tenantFor(getenv('AUDIT_DEFAULT_EMAIL'));

$out = $inTenant($openTenant, function () use ($openTenant) {
    $rule = TimingRule::query()->first() ?? new TimingRule(['delay_minutes_step2' => 4320, 'delay_minutes_step3' => 10080]);
    $rule->business_hours_start = '00:00:00';
    $rule->business_hours_end = '23:59:59';
    $rule->timezone = 'UTC';
    $rule->tenant_id = $openTenant;
    $rule->save();

    $svc = app(ContactEnrollmentService::class);
    $backlog = [];
    for ($i = 1; $i <= 50; $i++) {
        $backlog[] = $svc->create("Audit Backlog {$i}", '+1555000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), null, 'audit')->id;
    }
    $cap = $svc->create('Audit Cap Contact', '+15550009999', null, 'audit')->id;

    return ['open_tenant' => $openTenant, 'backlog_contacts' => $backlog, 'cap_contact' => $cap];
});

$out += $inTenant($defaultTenant, function () use ($defaultTenant) {
    $rule = TimingRule::findOrCreateDefault();
    $reset = app(ContactEnrollmentService::class)->create('Audit Reset Contact', '+15550008888', null, 'audit')->id;

    return [
        'default_tenant' => $defaultTenant,
        'default_rule' => [$rule->business_hours_start, $rule->business_hours_end, $rule->timezone],
        'reset_contact' => $reset,
    ];
});

file_put_contents(getenv('AUDIT_OUT'), json_encode($out, JSON_PRETTY_PRINT));
echo json_encode(['open_tenant' => $out['open_tenant'], 'cap' => $out['cap_contact'], 'reset' => $out['reset_contact'], 'default_rule' => $out['default_rule'], 'backlog_n' => count($out['backlog_contacts'])]), "\n";
