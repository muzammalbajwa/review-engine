<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * DatabaseTransactions (see tests/Pest.php) wraps the whole test in one
     * outer Postgres transaction. Laravel nests any DB::transaction() call
     * made during a simulated request (login/register's RLS bypass,
     * SetTenantContext's tenant-context transaction) as a SAVEPOINT inside
     * that outer transaction rather than a real, separate transaction — and
     * releasing a savepoint (the normal, successful-request path) does NOT
     * undo a set_config(..., true) call made within it. "Local to
     * transaction" scoping only resets at the end of the *outer* transaction
     * — i.e. the end of the whole test — not at the end of each simulated
     * request.
     *
     * Left alone, this means the RLS bypass/tenant-context flags one
     * simulated request sets leak into every subsequent simulated request in
     * the same test method, silently granting access a real request (each
     * with its own genuinely separate transaction) would never have. That's
     * not a hypothetical: it's exactly why the old auth:sanctum/RLS ordering
     * bug passed in this suite while failing on every real HTTP request (see
     * .claude/SECURITY.md #2) — resetting here is what makes this test suite
     * trustworthy enough to build the Phase 1 cross-tenant isolation gate on.
     *
     * Forcibly resetting these as session-level (not transaction-local)
     * config after every simulated request closes that gap regardless of the
     * savepoint mechanics.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        $this->resetRlsSessionState();

        // Same category of problem as the RLS state above, in a different
        // layer: Laravel's AuthManager caches each guard's resolved user for
        // the life of the container, which — unlike in production, where
        // every request gets a fresh container — persists across multiple
        // simulated requests within one test. A token deleted by the first
        // call (e.g. logout) would otherwise still authenticate the second
        // call in the same test, because the guard hands back its cached
        // User instead of re-resolving the (now-deleted) token.
        Auth::forgetGuards();

        return $response;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetRlsSessionState();

        // .env.testing uses CACHE_STORE=array, which — unlike the RLS state
        // above — isn't scoped to a transaction at all; it's a plain PHP
        // array that lives for the whole test *process*, not per test.
        // Laravel's login/register throttle (SECURITY.md #3) is built on
        // this cache, so without flushing here, attempt counts silently
        // accumulate across every test that hits a throttled route,
        // regardless of file or order — a test earlier in the run can make
        // a later, unrelated test hit 429 instead of what it's actually
        // checking for.
        Cache::flush();
    }

    private function resetRlsSessionState(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // A nil-UUID sentinel, not NULL/'' — once a custom GUC like this has
        // been set at all in a session, Postgres's RESET (and
        // set_config(name, NULL, ...), which is equivalent to RESET) does
        // not return it to a true "missing" NULL; current_setting(...,
        // missing_ok=true) comes back as '' instead (confirmed directly
        // against Postgres). The RLS policies cast this to ::uuid, and an
        // empty string fails that cast with a hard SQL error instead of the
        // graceful "no match" every policy expects when no tenant is active.
        // The nil UUID is syntactically valid (so the cast succeeds) and
        // can never equal a real tenant_id (tenants get random v4 UUIDs via
        // Str::uuid()), so it correctly denies every tenant_isolation policy
        // check without erroring.
        DB::statement("SELECT set_config('app.current_tenant_id', '00000000-0000-0000-0000-000000000000', false)");
        DB::statement("SELECT set_config('app.bypass_tenant_scope', 'false', false)");
        DB::statement("SELECT set_config('app.is_admin', 'false', false)");
    }
}
