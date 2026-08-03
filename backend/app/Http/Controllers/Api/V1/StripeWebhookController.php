<?php

namespace App\Http\Controllers\Api\V1;

use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

/**
 * Mandatory signature verification (.claude/SECURITY.md #5: "Stripe webhook:
 * verify the signature on every call"). Cashier's own WebhookController only
 * applies VerifyWebhookSignature when config('cashier.webhook.secret')
 * happens to be truthy — a missing/misconfigured STRIPE_WEBHOOK_SECRET
 * silently disables verification and accepts any payload unverified. This
 * overrides the constructor to apply the middleware unconditionally: a
 * missing secret then fails closed (every signature check fails, since
 * Stripe's real signature can never match an empty key) instead of failing
 * open.
 *
 * No event handlers are overridden — Cashier's built-in handlers already
 * sync subscription state through our extended Subscription model
 * (Cashier::useSubscriptionModel(), see AppServiceProvider).
 */
class StripeWebhookController extends CashierWebhookController
{
    public function __construct()
    {
        $this->middleware(VerifyWebhookSignature::class);
    }
}
