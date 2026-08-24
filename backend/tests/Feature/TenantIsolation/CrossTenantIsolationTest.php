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
use Laravel\Sanctum\PersonalAccessToken;

/**
 * .claude/TESTING.md gate #1: "as Tenant A, every read/write against
 * Tenant B returns nothing. Test BOTH with the global scope AND with it
 * bypassed (to prove RLS catches it)." No HTTP endpoint exists yet that
 * exposes cross-tenant reads (Phase 2+), so this exercises the same
 * two-layer mechanism a real request does directly: a real transaction,
 * the same set_config() call SetTenantContext makes, and the same
 * CurrentTenant singleton TenantScope reads.
 */
function seedTenantWithUser(string $name): array
{
    return DB::transaction(function () use ($name) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => $name, 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$name} Owner",
            'email' => Str::lower(Str::slug($name)).'-'.Str::random(6).'@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        return [$tenant, $user];
    });
}

/**
 * Mirrors exactly what SetTenantContext does for a real request: a real
 * transaction, the Postgres session variable RLS policies read, and the
 * CurrentTenant singleton TenantScope reads — then clears it in finally,
 * same as the middleware does.
 */
function actingAsTenant(string $tenantId, callable $callback): mixed
{
    return DB::transaction(function () use ($tenantId, $callback) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
        app(CurrentTenant::class)->set($tenantId);

        try {
            return $callback();
        } finally {
            app(CurrentTenant::class)->clear();
        }
    });
}

test('as Tenant A, reads against Tenant B users return nothing (app scope active)', function () {
    [$tenantA] = seedTenantWithUser('Tenant A Read');
    [$tenantB, $userB] = seedTenantWithUser('Tenant B Read');

    actingAsTenant($tenantA->id, function () use ($userB) {
        expect(User::find($userB->id))->toBeNull();
        expect(User::where('email', $userB->email)->first())->toBeNull();
        expect(User::all()->pluck('id'))->not->toContain($userB->id);
    });
});

test('as Tenant A, writes against Tenant B users affect nothing (app scope active)', function () {
    [$tenantA] = seedTenantWithUser('Tenant A Write');
    [$tenantB, $userB] = seedTenantWithUser('Tenant B Write');

    actingAsTenant($tenantA->id, function () use ($userB) {
        $updated = User::where('id', $userB->id)->update(['name' => 'Hacked By Tenant A']);
        expect($updated)->toBe(0);
    });

    // Confirm from Tenant B's own perspective that nothing actually changed.
    actingAsTenant($tenantB->id, function () use ($userB) {
        expect(User::find($userB->id)->name)->toBe($userB->name);
    });
});

/**
 * THE ONE THAT MATTERS MOST. Deliberately strips the Eloquent-layer
 * TenantScope (withoutGlobalScopes) and even skips Eloquent entirely for
 * some assertions (raw DB::table()) — if Postgres RLS weren't independently
 * enforcing isolation, every one of these would leak Tenant B's data.
 *
 * Previously also covered `subscriptions`/`lemon_squeezy_subscriptions` —
 * removed with the Lemon Squeezy package (App\Models\Subscription no
 * longer exists). Re-add equivalent RLS-bypass coverage for whatever
 * Paddle-backed subscription table replaces it; this gap is real, not
 * intentional.
 */
test('CRITICAL: RLS alone blocks Tenant B users when the app-layer scope is bypassed', function () {
    [$tenantA] = seedTenantWithUser('RLS Bypass Tenant A');
    [$tenantB, $userB] = seedTenantWithUser('RLS Bypass Tenant B');

    actingAsTenant($tenantA->id, function () use ($userB) {
        // Eloquent, global scope explicitly removed.
        expect(User::withoutGlobalScopes()->find($userB->id))->toBeNull();
        expect(User::withoutGlobalScopes()->where('email', $userB->email)->first())->toBeNull();

        $updated = User::withoutGlobalScopes()->where('id', $userB->id)->update(['name' => 'Hacked-RLS-Bypass']);
        expect($updated)->toBe(0);

        // No Eloquent at all — raw query builder against the same
        // connection. Proves this is a database guarantee, not something
        // Eloquent is adding on top that a raw query could route around.
        expect(DB::table('users')->where('id', $userB->id)->first())->toBeNull();
        expect(DB::table('users')->where('id', $userB->id)->update(['name' => 'Hacked-Raw-SQL']))->toBe(0);
    });

    // Confirm from Tenant B's own perspective that nothing actually changed,
    // even after all of the above.
    actingAsTenant($tenantB->id, function () use ($userB) {
        expect(User::find($userB->id)->name)->toBe($userB->name);
    });
});

/**
 * .claude/DATABASE.md/.claude/CLAUDE.md golden rule #2 applies identically
 * to every tenant table, not just the original Phase 1 four (tenants,
 * users, subscriptions, audit_logs) — the Phase 2 drip-engine tables
 * (sender_identities, timing_rules, messages) get exactly the same three
 * checks below: reads/writes with the app scope active, then RLS alone
 * with it deliberately bypassed.
 */
function seedTenantWithDripEngineData(string $name): array
{
    return DB::transaction(function () use ($name) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => $name, 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$name} Owner",
            'email' => Str::lower(Str::slug($name)).'-'.Str::random(6).'@example.com',
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
            'name' => "{$name} Customer",
            'email' => 'customer-'.Str::random(6).'@example.com',
            'status' => 'pending',
        ]);
        $contact->tenant_id = $tenantId;
        $contact->save();

        $sender = new SenderIdentity([
            'from_name' => $name,
            'from_email' => Str::lower(Str::slug($name)).'@example.com',
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

        return [$tenant, $sender, $timing, $message];
    });
}

test('as Tenant A, reads against Tenant B sender identities, timing rules, and messages return nothing (app scope active)', function () {
    [$tenantA] = seedTenantWithDripEngineData('Tenant A Drip Read');
    [$tenantB, $senderB, $timingB, $messageB] = seedTenantWithDripEngineData('Tenant B Drip Read');

    actingAsTenant($tenantA->id, function () use ($senderB, $timingB, $messageB) {
        expect(SenderIdentity::find($senderB->id))->toBeNull();
        expect(TimingRule::find($timingB->id))->toBeNull();
        expect(Message::find($messageB->id))->toBeNull();
        expect(Message::where('contact_id', $messageB->contact_id)->first())->toBeNull();
    });
});

test('as Tenant A, writes against Tenant B sender identities, timing rules, and messages affect nothing (app scope active)', function () {
    [$tenantA] = seedTenantWithDripEngineData('Tenant A Drip Write');
    [$tenantB, $senderB, $timingB, $messageB] = seedTenantWithDripEngineData('Tenant B Drip Write');

    actingAsTenant($tenantA->id, function () use ($senderB, $timingB, $messageB) {
        expect(SenderIdentity::where('id', $senderB->id)->update(['verified' => false]))->toBe(0);
        expect(TimingRule::where('id', $timingB->id)->delete())->toBe(0);
        expect(Message::where('id', $messageB->id)->update(['status' => 'sent', 'sent_at' => now()]))->toBe(0);
    });

    actingAsTenant($tenantB->id, function () use ($senderB, $timingB, $messageB) {
        expect(SenderIdentity::find($senderB->id)->verified)->toBeTrue();
        expect(TimingRule::find($timingB->id))->not->toBeNull();
        expect(Message::find($messageB->id)->status)->toBe('pending');
    });
});

test('CRITICAL: RLS alone blocks Tenant B sender identities, timing rules, and messages when the app-layer scope is bypassed', function () {
    [$tenantA] = seedTenantWithDripEngineData('RLS Bypass Drip Tenant A');
    [$tenantB, $senderB, $timingB, $messageB] = seedTenantWithDripEngineData('RLS Bypass Drip Tenant B');

    actingAsTenant($tenantA->id, function () use ($senderB, $timingB, $messageB) {
        expect(SenderIdentity::withoutGlobalScopes()->find($senderB->id))->toBeNull();
        expect(TimingRule::withoutGlobalScopes()->find($timingB->id))->toBeNull();
        expect(Message::withoutGlobalScopes()->find($messageB->id))->toBeNull();

        expect(SenderIdentity::withoutGlobalScopes()->where('id', $senderB->id)->update(['verified' => false]))->toBe(0);
        expect(Message::withoutGlobalScopes()->where('id', $messageB->id)->delete())->toBe(0);

        // No Eloquent at all — same raw-query proof the original gate
        // uses for users/subscriptions, now for the drip-engine tables.
        expect(DB::table('sender_identities')->where('id', $senderB->id)->first())->toBeNull();
        expect(DB::table('timing_rules')->where('id', $timingB->id)->first())->toBeNull();
        expect(DB::table('messages')->where('id', $messageB->id)->first())->toBeNull();
        expect(DB::table('messages')->where('id', $messageB->id)->update(['status' => 'sent']))->toBe(0);
    });

    actingAsTenant($tenantB->id, function () use ($senderB, $messageB) {
        expect(SenderIdentity::find($senderB->id)->verified)->toBeTrue();
        expect(Message::find($messageB->id)->status)->toBe('pending');
    });
});

/**
 * personal_access_tokens (Sanctum's own table) differs from every table
 * above: it has no Eloquent-layer TenantScope at all — BelongsToTenant
 * can't be applied to a vendor model this app doesn't own — so RLS is
 * the ONLY isolation layer here, not defense in depth on top of an
 * app-layer scope. That makes its policy more load-bearing than the
 * others, not less: there's no fallback layer if it were ever wrong.
 *
 * 2026_08_06_133207_add_tenant_id_and_rls_to_personal_access_tokens_table.php
 * closed a real, live-proven leak found during a 2026-08-06 audit: a raw
 * DB::table('personal_access_tokens')->get() issued while Tenant A's own
 * session context was active returned every tenant's token rows on the
 * connection — 75 of them at the time — including Tenant B's, because the
 * table had neither a tenant_id column nor a policy. The CRITICAL test
 * below reproduces that exact probe (same unfiltered DB::table() call, no
 * where() clause at all) and proves it now returns only Tenant A's own
 * row.
 */
function seedTenantWithApiKey(string $name): array
{
    return DB::transaction(function () use ($name) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => $name, 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$name} Owner",
            'email' => Str::lower(Str::slug($name)).'-'.Str::random(6).'@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        // Real tenant context is active in this transaction (set above),
        // so User::createToken()'s tenant_id stamp and the new RLS
        // policy's WITH CHECK agree — exactly what a real request has via
        // SetTenantContext/the 'tenant' middleware group.
        $token = $user->createToken('webhook-api-key', ['contacts:create']);

        return [$tenant, $user, $token->accessToken];
    });
}

test('as Tenant A, a normal query never returns Tenant B\'s api key', function () {
    [$tenantA] = seedTenantWithApiKey('Tenant A Api Key');
    [$tenantB, , $tokenB] = seedTenantWithApiKey('Tenant B Api Key');

    actingAsTenant($tenantA->id, function () use ($tokenB) {
        expect(PersonalAccessToken::find($tokenB->id))->toBeNull();
        expect(PersonalAccessToken::where('id', $tokenB->id)->update(['expires_at' => now()]))->toBe(0);
    });
});

test('CRITICAL: RLS alone blocks Tenant B\'s api key via a raw, unfiltered query — the exact leak this migration closed', function () {
    [$tenantA, $userA, $tokenA] = seedTenantWithApiKey('RLS Bypass Api Key Tenant A');
    [$tenantB, , $tokenB] = seedTenantWithApiKey('RLS Bypass Api Key Tenant B');

    actingAsTenant($tenantA->id, function () use ($userA, $tokenA, $tokenB) {
        // No where() clause at all — the literal reproduction of the live
        // probe that returned 75 cross-tenant rows before this table had
        // a tenant_id column or an RLS policy.
        $rows = DB::table('personal_access_tokens')->get();

        expect($rows->pluck('id')->all())->toBe([$tokenA->id]);
        expect($rows->pluck('tokenable_id')->all())->toBe([$userA->id]);
        expect($rows->contains('id', $tokenB->id))->toBeFalse();

        // Same proof against Eloquent — withoutGlobalScopes() is a no-op
        // here (there is no global scope on this vendor model), kept for
        // the same "prove it's a database guarantee, not an accident of
        // query construction" spirit as every other table's CRITICAL test.
        expect(PersonalAccessToken::withoutGlobalScopes()->find($tokenB->id))->toBeNull();
        expect(DB::table('personal_access_tokens')->where('id', $tokenB->id)->update(['expires_at' => now()]))->toBe(0);
    });

    actingAsTenant($tenantB->id, function () use ($tokenB) {
        expect(PersonalAccessToken::find($tokenB->id))->not->toBeNull();
        expect(PersonalAccessToken::find($tokenB->id)->expires_at)->toBeNull();
    });
});
