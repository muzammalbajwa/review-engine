<?php

namespace App\Console\Commands;

use App\Jobs\SendReviewRequest;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TimingRule;
use App\Services\Queue\BusinessHoursGate;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * .claude/QUEUE.md mechanic #3: "Reactivation drip (2–3 per 20 min,
 * business hours only): A scheduled command runs every 20 min, releases
 * the next 2–3 pending contacts, but ONLY if within the tenant's local
 * business hours + timezone. Never blast." This is the piece that was
 * entirely absent — the only thing that turns a 'pending' contact
 * (created by quick-add, CSV import, the webhook API, or anything calling
 * that same webhook — Zapier, Make) into an actual first send.
 *
 * Iterates per-tenant rather than one global query: business-hours gating
 * is per-tenant (timing_rules.timezone), and each tenant's release cap is
 * independent — a large tenant with hundreds of pending contacts must
 * never crowd out a small tenant's few, and one tenant well past their
 * batch cap has no bearing on any other tenant's release this tick.
 *
 * withoutOverlapping is applied at the schedule registration
 * (routes/console.php) too, same convention as gbp:sync-reviews — but
 * that only guards *scheduled* runs against each other. It says nothing
 * about a manual, out-of-band `php artisan drip:release-pending` racing a
 * scheduled tick, which is a real scenario, not a hypothetical (it's
 * exactly how a 2026-08-06 concurrency audit found and reproduced this
 * gap). releaseForTenant() selects `status = 'pending'` contacts with no
 * row locking and no pre-dispatch status change, so two overlapping calls
 * for the *same tenant* both see the same rows and both dispatch —
 * SendReviewRequest's own idempotency backstop (messages.UNIQUE +
 * firstOrCreate + status check) absorbs the customer-facing consequence,
 * but not before ~9-10 real, wasted DB queries run. The per-tenant
 * Cache::lock below closes the gap at the source instead of relying on
 * the backstop alone — same self-expiring shape as SendReviewRequest's
 * own WithoutOverlapping(...)->expireAfter(600) middleware, just applied
 * one level up, at the point contacts are selected rather than the point
 * they're sent.
 */
class ReleasePendingContacts extends Command
{
    protected $signature = 'drip:release-pending';

    protected $description = "Release up to 3 pending contacts per tenant, per tick, during the tenant's business hours only.";

    private const BATCH_SIZE_PER_TENANT = 3;

    private const LOCK_TIMEOUT_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 5;

    public function handle(BusinessHoursGate $businessHours): int
    {
        $tenantIds = $this->tenantIdsWithPendingContacts();

        $released = 0;

        foreach ($tenantIds as $tenantId) {
            $released += $this->releaseForTenantWithLock($tenantId, $businessHours);
        }

        $this->info("Released {$released} contact(s) across ".count($tenantIds).' tenant(s) with pending contacts.');

        return self::SUCCESS;
    }

    /**
     * The lock is acquired and released *outside* withTenantContext()'s own
     * DB::transaction() deliberately, not nested inside it. Cache::lock()'s
     * database driver acquires by attempting a plain INSERT (falling back
     * to an UPDATE on conflict) against the cache_locks table — nested
     * inside an already-open outer transaction, a second process's own
     * INSERT attempt against the same lock row would block at the Postgres
     * level on that still-uncommitted row, rather than failing fast and
     * letting block()'s own poll-and-retry loop work the way it's supposed
     * to. Acquiring first, then opening the tenant-context transaction
     * inside the held lock, keeps the lock's own read/write path on a
     * normal, quickly-committing footing.
     */
    private function releaseForTenantWithLock(string $tenantId, BusinessHoursGate $businessHours): int
    {
        try {
            return Cache::lock("drip:release-pending:{$tenantId}", self::LOCK_TIMEOUT_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($tenantId, $businessHours) {
                    return $this->withTenantContext($tenantId, fn () => $this->releaseForTenant($tenantId, $businessHours));
                });
        } catch (LockTimeoutException) {
            // Someone else (a scheduled tick, a manual invocation, an
            // overrunning previous release) is already releasing for this
            // exact tenant. Skip this tick entirely rather than wait
            // indefinitely — the next scheduled tick picks this tenant up
            // again in 20 minutes regardless, same "never blast" spirit
            // .claude/QUEUE.md already states for the release cadence
            // itself.
            Log::warning('drip:release-pending: could not acquire release lock for tenant within '.self::LOCK_WAIT_SECONDS.'s, skipping this tick', [
                'tenant_id' => $tenantId,
            ]);

            return 0;
        }
    }

    /**
     * Bypasses RLS deliberately, scoped to exactly this one read — there
     * is no tenant context to set yet, and the ids returned are used only
     * to loop and then immediately set that same tenant's own context
     * before touching anything else. Same reasoning as
     * SyncReviewsForConnection's admin-bypass lookup.
     */
    private function tenantIdsWithPendingContacts(): array
    {
        return DB::transaction(function () {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            return Contact::withoutGlobalScopes()
                ->where('status', 'pending')
                ->distinct()
                ->pluck('tenant_id')
                ->all();
        });
    }

    /**
     * Same CurrentTenant + Postgres-session-var pairing SendReviewRequest's
     * own withTenantContext() uses, for the identical reason: this loop
     * calls TimingRule::findOrCreateDefault(), whose create() path relies
     * on BelongsToTenant's auto-fill, which reads CurrentTenant — not the
     * raw DB session variable RLS checks. Setting only the latter left
     * tenant_id unset on a first-ever TimingRule row (caught the same way,
     * via this command's own test).
     */
    private function withTenantContext(string $tenantId, \Closure $callback): mixed
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

    /**
     * Defense in depth for the trial-expiry gate: RequireSendingAccess
     * (HTTP middleware) already stops NEW pending contacts from being
     * created once a tenant is trial_expired, but a batch imported right
     * before expiry could otherwise sit as 'pending' and keep trickling
     * out release-by-release for weeks afterward — this is what actually
     * stops the send, not just the entry point. No log line for the skip
     * itself (unlike the lock-timeout warning below): this would fire
     * every tick for every trial_expired tenant with leftover pending
     * contacts indefinitely, which is noise, not signal — the tenant
     * already sees why in the app (RequireSendingAccess's own message).
     */
    private function releaseForTenant(string $tenantId, BusinessHoursGate $businessHours): int
    {
        if (Tenant::query()->find($tenantId)?->sendingBlocked() === true) {
            return 0;
        }

        $timingRule = TimingRule::findOrCreateDefault();

        if (! $businessHours->isOpen($timingRule)) {
            return 0;
        }

        $contacts = Contact::query()
            ->where('status', 'pending')
            ->oldest('created_at')
            ->limit(self::BATCH_SIZE_PER_TENANT)
            ->get(['id']);

        foreach ($contacts as $contact) {
            SendReviewRequest::dispatch($contact->id, 1);
        }

        if ($contacts->isNotEmpty()) {
            Log::info('drip:release-pending released contacts', [
                'tenant_id' => $tenantId,
                'count' => $contacts->count(),
            ]);
        }

        return $contacts->count();
    }
}
