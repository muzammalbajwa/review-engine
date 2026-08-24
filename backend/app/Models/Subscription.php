<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Laravel\Paddle\Subscription as PaddleSubscription;

/**
 * Extends laravel/cashier-paddle's own Subscription model (registered via
 * Cashier::useSubscriptionModel() in AppServiceProvider) purely to attach
 * BelongsToTenant — same reasoning as App\Models\Customer. The package's
 * real columns/behavior (paddle_id, status, the billable morph,
 * trial_ends_at/paused_at/ends_at) are untouched.
 *
 * tenant_id auto-fills from CurrentTenant — for the one place this app
 * ever creates a Subscription row, that's always inside
 * PaddleWebhookController's tenant-context-resolved transaction (Cashier's
 * own handleSubscriptionCreated(), called via parent::__invoke() —
 * see that controller's docblock for how tenant_id gets resolved and set
 * *before* delegating).
 *
 * Auto-renew-toggle-style helpers (the previous processor's autoRenews()/
 * periodEnd()) intentionally not ported yet — no PATCH /subscription
 * (cancel/resume) endpoint exists this step; Paddle's own subscription
 * status (STATUS_ACTIVE/STATUS_PAST_DUE/STATUS_PAUSED/STATUS_CANCELED)
 * is read directly by PaddleWebhookController's STATUS_MAP for now.
 */
class Subscription extends PaddleSubscription
{
    use BelongsToTenant;
}
