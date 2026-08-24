<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * lemonsqueezy/laravel auto-creates lemon_squeezy_orders,
 * lemon_squeezy_license_keys, and lemon_squeezy_license_key_instances
 * alongside the customers/subscriptions tables this app actually uses.
 * ReviewEngine sells one subscription product, not one-off single-payment
 * orders or licensed software — none of these three tables were ever going
 * to be given tenant_id + RLS the way lemon_squeezy_customers/
 * lemon_squeezy_subscriptions were (add_tenant_id_to_lemon_squeezy_*_table,
 * enable_rls_on_lemon_squeezy_tables).
 *
 * .claude/SECURITY.md #2 / CLAUDE.md golden rule #2: "Every DB table with
 * tenant data has a tenant_id... Both. Always." — an un-RLS'd table sitting
 * around holding customer_id/user_email/amounts (lemon_squeezy_orders) is
 * exactly the kind of latent cross-tenant leak that rule exists to prevent,
 * even though nothing in this app currently queries it. Dropping it removes
 * the exposure outright instead of leaving it "unused but dangerous."
 *
 * Safe: WebhookController::handleOrderCreated/handleOrderRefunded already
 * guard with Schema::hasTable() and no-op (dispatching their event with a
 * null $order) when the table doesn't exist — an intentionally-supported
 * degraded mode per that method's own "Todo v2: Remove this check"
 * comment, not a workaround. handleLicenseKeyCreated/Updated have no such
 * guard, but we will never receive a license_key_* webhook at all — no
 * variant in this store has licensing enabled, and this app has no feature
 * that would use one. If that ever changes, add tenant_id + RLS to these
 * three tables the same way the other two got it, rather than reversing
 * this migration blind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lemon_squeezy_license_key_instances');
        Schema::dropIfExists('lemon_squeezy_license_keys');
        Schema::dropIfExists('lemon_squeezy_orders');
    }

    public function down(): void
    {
        // Deliberately not recreated here — see docblock. If licensing or
        // single-payment orders become a real feature, add tenant_id + RLS
        // from the start rather than resurrecting the package's un-scoped
        // originals.
    }
};
