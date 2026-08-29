<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_logs — a durable, append-only record of individual Paddle
 * payment *attempts* (succeeded/failed/refunded), which nothing in this
 * codebase tracked before this migration. Cashier-paddle's own
 * `transactions` table (create_transactions_table.php,
 * add_tenant_id_to_cashier_paddle_tables.php) is NOT this: it only ever
 * gets a row on transaction.completed (keyed uniquely on paddle_id) and
 * that same row is then mutated in place by transaction.updated — a
 * later refund overwrites the row's `status` rather than adding a new
 * entry, and a failed attempt (transaction.payment_failed) never creates
 * a row there at all (see PaddleWebhookController::
 * handleTransactionPaymentFailed's pre-existing docblock, which
 * explicitly says so). This table exists specifically to stop losing
 * that history.
 *
 * Composite unique(paddle_transaction_id, status), not a bare unique on
 * paddle_transaction_id alone: a refund is logged against the SAME
 * paddle_transaction_id as the original charge it refunds (Paddle
 * Billing has no separate "refund transaction" id — a refund is an
 * Adjustment referencing the original transaction_id; see
 * PaddleWebhookController::maybeLogRefund's docblock for why
 * adjustment.* events, not a transaction.refunded event, are the real
 * signal). A single-column unique constraint would make a transaction's
 * 'succeeded' and later 'refunded' rows collide. The composite key still
 * gives the required idempotency: a retried delivery of the exact same
 * webhook (same transaction id, same outcome) firstOrCreate()s onto the
 * same row instead of duplicating it.
 *
 * RLS: same NULLIF(...) two-layer pattern as every other tenant table
 * since harden_tenant_isolation_policy_against_empty_string_leftover.php
 * — tenant_isolation_admin's app.is_admin bypass is what makes this
 * cross-tenant-viewable for super-admin (CLAUDE.md golden rule #2), the
 * same mechanism every other admin-readable tenant table already uses;
 * no new cross-tenant mechanism was built for this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('paddle_transaction_id');
            $table->string('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('failure_reason')->nullable();
            $table->string('billing_interval')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['paddle_transaction_id', 'status']);
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON payment_logs');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON payment_logs');
        DB::statement('ALTER TABLE payment_logs NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_logs DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('payment_logs');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE payment_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_logs FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON payment_logs
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON payment_logs
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
