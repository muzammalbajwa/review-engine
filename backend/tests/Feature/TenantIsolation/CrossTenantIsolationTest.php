<?php

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * .claude/TESTING.md gate #1: "as Tenant A, every read/write against
 * Tenant B returns nothing. Test BOTH with the global scope AND with it
 * bypassed (to prove RLS catches it)." No HTTP endpoint exists yet that
 * exposes cross-tenant reads (Phase 2+), so this exercises the same
 * two-layer mechanism a real request does directly: a real transaction,
 * the same set_config() call SetTenantContext makes, and the same
 * CurrentTenant singleton TenantScope reads.
 */
function seedTenantWithUserAndSubscription(string $name): array
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

        $subscription = new Subscription([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(14),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_placeholder',
        ]);
        $subscription->user_id = $user->id;
        $subscription->tenant_id = $tenantId;
        $subscription->save();

        return [$tenant, $user, $subscription];
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

test('as Tenant A, reads against Tenant B users and subscriptions return nothing (app scope active)', function () {
    [$tenantA] = seedTenantWithUserAndSubscription('Tenant A Read');
    [$tenantB, $userB, $subscriptionB] = seedTenantWithUserAndSubscription('Tenant B Read');

    actingAsTenant($tenantA->id, function () use ($userB, $subscriptionB) {
        expect(User::find($userB->id))->toBeNull();
        expect(User::where('email', $userB->email)->first())->toBeNull();
        expect(User::all()->pluck('id'))->not->toContain($userB->id);

        expect(Subscription::find($subscriptionB->id))->toBeNull();
        expect(Subscription::where('stripe_id', $subscriptionB->stripe_id)->first())->toBeNull();
    });
});

test('as Tenant A, writes against Tenant B users and subscriptions affect nothing (app scope active)', function () {
    [$tenantA] = seedTenantWithUserAndSubscription('Tenant A Write');
    [$tenantB, $userB, $subscriptionB] = seedTenantWithUserAndSubscription('Tenant B Write');

    actingAsTenant($tenantA->id, function () use ($userB, $subscriptionB) {
        $updated = User::where('id', $userB->id)->update(['name' => 'Hacked By Tenant A']);
        expect($updated)->toBe(0);

        $deleted = Subscription::where('id', $subscriptionB->id)->delete();
        expect($deleted)->toBe(0);
    });

    // Confirm from Tenant B's own perspective that nothing actually changed.
    actingAsTenant($tenantB->id, function () use ($userB, $subscriptionB) {
        expect(User::find($userB->id)->name)->toBe($userB->name);
        expect(Subscription::find($subscriptionB->id))->not->toBeNull();
    });
});

/**
 * THE ONE THAT MATTERS MOST. Deliberately strips the Eloquent-layer
 * TenantScope (withoutGlobalScopes) and even skips Eloquent entirely for
 * some assertions (raw DB::table()) — if Postgres RLS weren't independently
 * enforcing isolation, every one of these would leak Tenant B's data.
 */
test('CRITICAL: RLS alone blocks Tenant B users and subscriptions when the app-layer scope is bypassed', function () {
    [$tenantA] = seedTenantWithUserAndSubscription('RLS Bypass Tenant A');
    [$tenantB, $userB, $subscriptionB] = seedTenantWithUserAndSubscription('RLS Bypass Tenant B');

    actingAsTenant($tenantA->id, function () use ($userB, $subscriptionB) {
        // Eloquent, global scope explicitly removed.
        expect(User::withoutGlobalScopes()->find($userB->id))->toBeNull();
        expect(User::withoutGlobalScopes()->where('email', $userB->email)->first())->toBeNull();
        expect(Subscription::withoutGlobalScopes()->find($subscriptionB->id))->toBeNull();
        expect(Subscription::withoutGlobalScopes()->where('stripe_id', $subscriptionB->stripe_id)->first())->toBeNull();

        $updated = User::withoutGlobalScopes()->where('id', $userB->id)->update(['name' => 'Hacked-RLS-Bypass']);
        expect($updated)->toBe(0);

        $deleted = Subscription::withoutGlobalScopes()->where('id', $subscriptionB->id)->delete();
        expect($deleted)->toBe(0);

        // No Eloquent at all — raw query builder against the same
        // connection. Proves this is a database guarantee, not something
        // Eloquent is adding on top that a raw query could route around.
        expect(DB::table('users')->where('id', $userB->id)->first())->toBeNull();
        expect(DB::table('subscriptions')->where('id', $subscriptionB->id)->first())->toBeNull();
        expect(DB::table('users')->where('id', $userB->id)->update(['name' => 'Hacked-Raw-SQL']))->toBe(0);
    });

    // Confirm from Tenant B's own perspective that nothing actually changed,
    // even after all of the above.
    actingAsTenant($tenantB->id, function () use ($userB) {
        expect(User::find($userB->id)->name)->toBe($userB->name);
    });
});
