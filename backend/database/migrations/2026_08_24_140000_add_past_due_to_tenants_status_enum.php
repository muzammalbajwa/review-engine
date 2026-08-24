<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .claude/BILLING.md's dunning design: `past_due` is promoted from a
 * silent no-op (the previous processor never wrote it to tenant.status
 * at all) to a real sixth state. tenants.status is a real Postgres CHECK
 * constraint (2026_08_10_090000_add_trial_fields_to_tenants_table.php's
 * $table->enum(...) — Laravel compiles enum() to a CHECK constraint on
 * pgsql, not a native type), not the "app-level validation only"
 * convention `plan`/`billing_interval` use on this same table — simply
 * writing 'past_due' from PHP without this migration fails at the
 * database with a constraint violation, confirmed against this exact
 * environment's schema (`\d tenants` shows tenants_status_check) before
 * writing this.
 *
 * Postgres has no ALTER TABLE ... ALTER COLUMN ... enum-add-value
 * shortcut for a CHECK-constraint-based enum the way a native ENUM type
 * would — drop and recreate the constraint with the extended value list.
 * `enum()`'s auto-generated constraint name (tenants_status_check) is
 * used directly rather than looked up dynamically: this table has never
 * had more than one CHECK constraint on `status`, so there's no ambiguity
 * to guard against.
 *
 * PaddleWebhookController::STATUS_MAP is the only writer of this new
 * value (Subscription::STATUS_PAST_DUE -> 'past_due', both from
 * subscription.updated reporting Paddle's own past_due status and from
 * the transaction.payment_failed override — see that controller).
 * Tenant::sendingBlockedReason() deliberately has no 'past_due' branch —
 * a failed payment does NOT block sending access (confirmed product
 * decision, matching the same "not aggressive on the first failure"
 * reasoning this app already applied before past_due was even a real
 * state) — only 'canceled' does, once Paddle's own dunning genuinely
 * exhausts and the subscription ends.
 */
return new class extends Migration
{
    private const OLD_VALUES = ['pending', 'trialing', 'active', 'trial_expired', 'canceled'];

    private const NEW_VALUES = ['pending', 'trialing', 'active', 'trial_expired', 'past_due', 'canceled'];

    public function up(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT tenants_status_check');
        DB::statement($this->checkConstraintSql(self::NEW_VALUES));
    }

    public function down(): void
    {
        // Any tenant currently sitting at 'past_due' would violate the
        // narrower constraint being restored here — same "not meant to
        // be rolled back live with real data present" caveat every
        // enum-narrowing migration in this app carries. Fine for this
        // environment (confirmed no real Paddle subscribers exist yet,
        // same check every processor-swap migration in this app has
        // recorded before it ran).
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT tenants_status_check');
        DB::statement($this->checkConstraintSql(self::OLD_VALUES));
    }

    private function checkConstraintSql(array $values): string
    {
        $list = collect($values)->map(fn (string $value) => "'{$value}'::character varying")->implode(', ');

        return "ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status::character varying = ANY (ARRAY[{$list}]))";
    }
};
