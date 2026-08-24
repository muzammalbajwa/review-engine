<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Laravel\Paddle\SubscriptionItem as PaddleSubscriptionItem;

/**
 * Extends laravel/cashier-paddle's own SubscriptionItem model (registered
 * via Cashier::useSubscriptionItemModel() in AppServiceProvider) purely
 * to attach BelongsToTenant. Unlike Customer/Subscription/Transaction,
 * this table has no billable morph of its own — only a plain
 * subscription_id FK — but still gets the same tenant_id + RLS + global
 * scope treatment as every other tenant-owned table (CLAUDE.md golden
 * rule #2 makes no exception for "child of a tenant-scoped row"; see the
 * migration adding tenant_id to all four cashier-paddle tables for why).
 * tenant_id auto-fills from CurrentTenant the same way it does for
 * Subscription — items are only ever created inside
 * PaddleWebhookController's tenant-context-resolved transaction.
 */
class SubscriptionItem extends PaddleSubscriptionItem
{
    use BelongsToTenant;
}
