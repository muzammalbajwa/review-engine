<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\SenderIdentity;
use App\Models\Tenant;
use App\Models\TimingRule;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * .claude/DATABASE.md / .claude/CLAUDE.md golden rule #2: "Every DB table
 * with tenant data has a tenant_id. Every query is scoped by it. Enforced
 * by a global scope AND Postgres Row-Level Security. Both. Always." —
 * verified immediately for the three Phase 2 drip-engine tables, same as
 * every other tenant table in this codebase, rather than deferred to the
 * later send-job test pass.
 */
function seedTenantWithDripData(string $label): array
{
    return DB::transaction(function () use ($label) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => "{$label} Co", 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        $contact = new Contact([
            'campaign_id' => $campaign->id,
            'name' => "{$label} Customer",
            'email' => 'customer-'.uniqid().'@example.com',
            'status' => 'pending',
        ]);
        $contact->tenant_id = $tenantId;
        $contact->save();

        $sender = new SenderIdentity([
            'from_name' => $label,
            'from_email' => strtolower(str_replace(' ', '', $label)).'@example.com',
            'verified' => true,
        ]);
        $sender->tenant_id = $tenantId;
        $sender->save();

        $timing = new TimingRule([
            'delay_minutes_step2' => 4320,
            'delay_minutes_step3' => 10080,
            'business_hours_start' => '09:00:00',
            'business_hours_end' => '18:00:00',
            'timezone' => 'America/New_York',
        ]);
        $timing->tenant_id = $tenantId;
        $timing->save();

        $message = new Message([
            'contact_id' => $contact->id,
            'step' => 1,
            'status' => 'pending',
        ]);
        $message->tenant_id = $tenantId;
        $message->save();

        return [$tenantId, $sender, $timing, $message];
    });
}

test('a tenant can never see another tenant\'s sender identities, timing rules, or messages', function () {
    [$tenantAId, $senderA, $timingA, $messageA] = seedTenantWithDripData('Drip Isolation A');
    [$tenantBId] = seedTenantWithDripData('Drip Isolation B');

    DB::transaction(function () use ($tenantBId, $senderA, $timingA, $messageA) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantBId]);
        app(CurrentTenant::class)->set($tenantBId);

        // Layer A (app scope) active.
        expect(SenderIdentity::find($senderA->id))->toBeNull();
        expect(TimingRule::find($timingA->id))->toBeNull();
        expect(Message::find($messageA->id))->toBeNull();

        // Layer B (RLS) alone, scope deliberately bypassed.
        expect(SenderIdentity::withoutGlobalScopes()->find($senderA->id))->toBeNull();
        expect(TimingRule::withoutGlobalScopes()->find($timingA->id))->toBeNull();
        expect(Message::withoutGlobalScopes()->find($messageA->id))->toBeNull();

        app(CurrentTenant::class)->clear();
    });
});

test('each tenant only sees its own row when scoped correctly', function () {
    [$tenantAId, $senderA, $timingA, $messageA] = seedTenantWithDripData('Drip Own Data');

    DB::transaction(function () use ($tenantAId, $senderA, $timingA, $messageA) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantAId]);
        app(CurrentTenant::class)->set($tenantAId);

        expect(SenderIdentity::find($senderA->id)?->id)->toBe($senderA->id);
        expect(TimingRule::find($timingA->id)?->id)->toBe($timingA->id);
        expect(Message::find($messageA->id)?->id)->toBe($messageA->id);

        app(CurrentTenant::class)->clear();
    });
});
