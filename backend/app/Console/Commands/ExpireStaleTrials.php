<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The 7-day free trial's other half (Tenant::startTrial() sets it in
 * motion; this is what ends it). Runs daily (routes/console.php),
 * transitioning any tenant whose trial_ends_at has passed from
 * 'trialing' to 'trial_expired' — the one thing RequireSendingAccess and
 * drip:release-pending's own guard both gate on.
 *
 * "with no active paid subscription" (the decision doc's own phrasing)
 * needs no separate Subscription-table check here: in this design,
 * status only ever becomes 'active' via a real Cashier conversion
 * (SubscriptionController::subscribe) — status = 'trialing' already
 * means "no paid subscription exists" by construction, not by inference.
 * Re-deriving that from a second table would be two sources of truth for
 * the same fact.
 *
 * CRITICAL: this command only ever writes tenants.status. It never
 * touches contacts, campaigns, templates, or messages — trial expiry is
 * a status flip, not a data operation (see
 * tests/Feature/Billing/TrialExpiryDataIntegrityTest.php).
 */
class ExpireStaleTrials extends Command
{
    protected $signature = 'trial:expire';

    protected $description = "Transition tenants whose 7-day trial has ended to status = 'trial_expired'.";

    public function handle(): int
    {
        $tenantIds = $this->staleTrialTenantIds();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            if ($this->expireTenant($tenantId)) {
                $expired++;
            }
        }

        $this->info("Expired {$expired} trial(s) out of ".count($tenantIds).' candidate tenant(s).');

        return self::SUCCESS;
    }

    /**
     * Bypasses RLS deliberately, scoped to exactly this one read — same
     * pattern as ReleasePendingContacts::tenantIdsWithPendingContacts().
     * No tenant context exists yet to read across every tenant's
     * trial_ends_at; the ids returned are only ever used to look each
     * tenant up again individually (and re-verify its status) before any
     * write happens.
     */
    private function staleTrialTenantIds(): array
    {
        return DB::transaction(function () {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            return Tenant::query()
                ->where('status', 'trialing')
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<=', now())
                ->pluck('id')
                ->all();
        });
    }

    /**
     * Re-checked individually inside its own transaction rather than
     * trusted from the bulk read above — a tenant could have converted
     * (gone 'active') in the gap between that read and this write, and
     * status is re-verified here so this can never clobber a status a
     * concurrent conversion just set. Same "don't trust a stale batch
     * read for the actual mutation" reasoning as
     * ReleasePendingContacts::releaseForTenantWithLock's own docblock —
     * no Cache::lock needed here though: unlike releasing contacts
     * (which has a real side effect, dispatching a job, if done twice),
     * this write is idempotent — re-running it against an
     * already-trial_expired tenant is a no-op, not a duplicate action.
     */
    private function expireTenant(string $tenantId): bool
    {
        return DB::transaction(function () use ($tenantId) {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null || $tenant->status !== 'trialing') {
                return false;
            }

            if ($tenant->trial_ends_at === null || $tenant->trial_ends_at->isFuture()) {
                return false;
            }

            $tenant->status = 'trial_expired';
            $tenant->save();

            Log::info('trial:expire transitioned tenant to trial_expired', [
                'tenant_id' => $tenantId,
                'trial_ends_at' => $tenant->trial_ends_at->toIso8601String(),
            ]);

            return true;
        });
    }
}
