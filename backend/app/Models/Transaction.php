<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Laravel\Paddle\Transaction as PaddleTransaction;

/**
 * Extends laravel/cashier-paddle's own Transaction model (registered via
 * Cashier::useTransactionModel() in AppServiceProvider) purely to attach
 * BelongsToTenant — same reasoning as App\Models\Customer/Subscription.
 * tenant_id auto-fills from CurrentTenant the same way — every row this
 * app creates comes from Cashier's own handleTransactionCompleted()/
 * handleTransactionUpdated(), called via parent::__invoke() inside
 * PaddleWebhookController's tenant-context-resolved transaction.
 */
class Transaction extends PaddleTransaction
{
    use BelongsToTenant;
}
