<?php

namespace App\Console\Commands;

use App\Jobs\SyncReviewsForConnection;
use App\Models\GbpConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncGbpReviews extends Command
{
    protected $signature = 'gbp:sync-reviews';

    protected $description = 'Dispatch a review-sync job for every connected Google Business Profile.';

    /**
     * A scheduled CLI command has no request/tenant context at all — no
     * SetTenantContext middleware ever runs for it, so RLS hides every row
     * by default. withoutGlobalScopes() only removes the *Eloquent* layer;
     * the DB layer still needs app.is_admin for this legitimately
     * cross-tenant read. Unlike AdminTenantController's cross-tenant reads,
     * this isn't a human looking at another tenant's data — it's the
     * system doing its own scheduled work — so it doesn't write an
     * audit_logs row (that table's actor_id is a real user, by design;
     * there isn't one here).
     */
    public function handle(): int
    {
        $count = DB::transaction(function () {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            $connections = GbpConnection::withoutGlobalScopes()
                ->where('status', 'connected')
                ->get();

            foreach ($connections as $connection) {
                SyncReviewsForConnection::dispatch($connection->id);
            }

            return $connections->count();
        });

        $this->info("Dispatched review sync for {$count} connection(s).");

        return self::SUCCESS;
    }
}
