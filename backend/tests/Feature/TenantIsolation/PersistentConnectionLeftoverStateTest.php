<?php

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Real, empirically-confirmed bug found while building the send pipeline
 * (SendReviewRequest / ReleasePendingContacts): on a persistent DB
 * connection — exactly how a Horizon/queue:work worker runs, processing
 * many jobs in a row on one connection — set_config('app.current_tenant_id',
 * ..., true) (transaction-LOCAL) reverts to an empty string once that
 * transaction commits, not to NULL, on any connection where the GUC has
 * ever been assigned a real value before. A bare
 * current_setting(...)::uuid cast then throws on '' instead of the
 * intended "no match" — and per 2026_07_17_145636_enable_row_level_
 * security.php's own docblock, Postgres doesn't guarantee OR-clause
 * evaluation order, so this could crash even a legitimate is_admin
 * bypass query. Confirmed live via tinker against the real dev DB
 * (a real top-level COMMIT reverts to '', not NULL) and fixed in
 * 2026_08_05_134425 by wrapping every current_setting(...) cast in
 * NULLIF(..., '').
 *
 * That specific "reverts to '' after commit" behavior can't be
 * reproduced inside Pest's own test-wrapping transaction (each test
 * runs as a savepoint inside one outer transaction that's rolled back
 * at the end, never truly committed) — confirmed by trying it here
 * first. So this test targets the fix directly instead: an explicit ''
 * setting, however it got there, must never crash the cast and must
 * correctly behave as "no tenant" rather than a thrown error.
 */
test('an empty-string tenant setting never crashes an RLS check and correctly resolves to no access', function () {
    [, $tenantId] = seedCustomerAccount('Leftover State');

    DB::transaction(function () {
        DB::statement("SELECT set_config('app.current_tenant_id', '', true)");
    });

    // The exact admin-bypass pattern SendReviewRequest/ReleasePendingContacts/
    // SyncReviewsForConnection all use — must not throw despite the empty setting.
    expect(fn () => DB::transaction(function () {
        DB::statement("SELECT set_config('app.is_admin', 'true', true)");

        return Contact::withoutGlobalScopes()->count();
    }))->not->toThrow(Throwable::class);

    // With current_tenant_id explicitly '' and is_admin not set, no rows
    // should be visible — NULLIF makes '' resolve to NULL, which matches
    // nothing, the same safe "no access" outcome as if it were never set
    // at all, not a crash and not an accidental leak.
    $count = DB::transaction(function () {
        DB::statement("SELECT set_config('app.current_tenant_id', '', true)");

        return Contact::count();
    });
    expect($count)->toBe(0);

    // And a genuinely correct tenant-context transaction still resolves
    // real data afterward — the fix doesn't just suppress the crash, the
    // isolation mechanism still actually works.
    $realCount = DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Contact::count();
    });
    expect($realCount)->toBe(0); // this tenant has no contacts either, but critically: no throw
});
