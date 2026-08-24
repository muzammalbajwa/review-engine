<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .claude/BILLING.md's single-plan pricing change: monthly vs. annual is
 * now the only choice a converting tenant makes (there's one plan, not
 * three tiers). Nullable, plain string (not an enum) — matches
 * `plan`/`status`'s existing "app-level validation, not a DB constraint"
 * convention on this table.
 *
 * Set once, at real conversion (SubscriptionController::subscribe /
 * LemonSqueezyWebhookController's subscription_created handler) — never
 * during the card-less trial, same "no billing object exists before actual
 * conversion" rule the whole trial design is built on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_interval')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_interval');
        });
    }
};
