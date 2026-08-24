<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use LemonSqueezy\Laravel\Customer as LemonSqueezyCustomer;

/**
 * Extends the Lemon Squeezy package's own Customer model (registered via
 * LemonSqueezy::useCustomerModel() in AppServiceProvider) purely to attach
 * BelongsToTenant — same reasoning as App\Models\Subscription.
 */
class Customer extends LemonSqueezyCustomer
{
    use BelongsToTenant;
}
