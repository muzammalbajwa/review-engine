<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscribeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Paddle\Subscription as PaddleSubscription;

/**
 * The conversion flow (Settings/Billing) for a trialing or trial_expired
 * tenant — also reachable by a tenant who wants to skip the trial and pay
 * immediately, from onboarding.
 *
 * Paddle's overlay checkout (.claude/BILLING.md — Cashier's standard
 * integration pattern; the frontend loads Paddle.js and calls
 * `Paddle.Checkout.open(options)` with exactly what this returns) rather
 * than a hosted-checkout redirect: this does NOT activate the tenant or
 * even guarantee a checkout is ever completed — it only returns the
 * options payload Paddle.js needs to open the overlay. Activation happens
 * asynchronously: once the customer pays inside the overlay, Paddle sends
 * a subscription_created webhook, and PaddleWebhookController is what
 * actually sets tenant.status = 'active' and tenant.billing_interval.
 */
class SubscriptionController extends Controller
{
    /**
     * checkout() (not subscribe(), which would silently overwrite its own
     * ['subscription_type' => ...] customData the moment a second
     * ->customData() call replaced it — Checkout::customData() assigns
     * wholesale, it does not merge) — this sets both custom_data keys at
     * once: subscription_type for Cashier's own handleSubscriptionCreated
     * (`$data['custom_data']['subscription_type'] ?? Subscription::
     * DEFAULT_TYPE`), and tenant_id as a defense-in-depth cross-check
     * PaddleWebhookController verifies against (never trusted alone) —
     * primary tenant resolution there is the already-existing Customer
     * row's own tenant_id (created synchronously below via
     * createAsCustomer(), before Paddle ever sends a webhook), not
     * custom_data (.claude/SECURITY.md #1: never the sole source of truth
     * for anything a webhook acts on).
     *
     * createAsCustomer() (checkout()'s first step) is a real, synchronous
     * outbound Paddle API call — same "honest failure state, not a
     * generic 500" treatment the old Lemon Squeezy flow used for its own
     * checkout-creation call, live-confirmed against this exact
     * environment (no real credentials configured yet in some
     * environments) not a hypothetical.
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $price = config("plans.standard.intervals.{$data['interval']}.price");
        $tenant = $request->user()->tenant;

        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');

        try {
            $checkout = $request->user()
                ->checkout($price)
                ->customData([
                    'subscription_type' => PaddleSubscription::DEFAULT_TYPE,
                    'tenant_id' => $tenant->id,
                ])
                ->returnTo("{$frontendUrl}/settings");
        } catch (\Throwable) {
            return response()->json([
                'error' => 'billing_unavailable',
                'message' => 'Checkout isn\'t available right now. Try again shortly.',
                'fields' => null,
            ], 502);
        }

        return response()->json(['data' => $checkout->options()], 201);
    }

    /**
     * Settings/Billing's one read of "where does this tenant stand."
     * tenant.status/plan/billing_interval/trial_ends_at are authoritative
     * — they're the only fields that mean anything during
     * trialing/trial_expired, when no Subscription row exists at all.
     *
     * auto_renew/current_period_end (the previous processor's cancel/
     * resume toggle state) intentionally not ported yet — no
     * PATCH /subscription endpoint exists this step (checkout + webhook
     * handling only, per this round's scope).
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        return response()->json(['data' => [
            'plan' => $tenant->plan,
            'billing_interval' => $tenant->billing_interval,
            'status' => $tenant->status,
            'trial_ends_at' => $tenant->trial_ends_at,
        ]]);
    }
}
