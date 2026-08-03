<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Extends Cashier's own Subscription model (registered via
 * Cashier::useSubscriptionModel() in AppServiceProvider) purely to attach
 * BelongsToTenant. Cashier's real columns/behavior (stripe_status,
 * stripe_price, type, the Billable-owning user_id) are untouched — see the
 * Phase 1 Step 1 decision on the subscriptions table conflict.
 *
 * tenant_id auto-fills from CurrentTenant the same way it does for User,
 * which is correct here because POST /subscribe always runs behind the
 * tenant-context middleware — by the time a Subscription is created,
 * CurrentTenant is already resolved from the authenticated user.
 */
class Subscription extends CashierSubscription
{
    use BelongsToTenant;
}
