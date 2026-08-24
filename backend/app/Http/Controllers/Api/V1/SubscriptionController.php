<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscribeRequest;
use App\Http\Requests\Billing\UpdateAutoRenewRequest;
use App\Models\Tenant;
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
     * (they're the only fields that mean anything during
     * trialing/trial_expired, when no Subscription row exists at all) —
     * a real subscription, when one exists, only adds `auto_renew`/
     * `current_period_end`/`ends_at` (App\Models\Subscription::
     * autoRenews()/periodEnd()), same shape the previous processor's
     * payload used.
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $subscription = $request->user()->subscription('default');

        return response()->json(['data' => $this->subscriptionPayload($tenant, $subscription)]);
    }

    /**
     * Settings/Billing's auto-renew toggle. `false` calls
     * Subscription::cancel(false) — Paddle's real cancel-at-period-end
     * (POST /subscriptions/{id}/cancel with effective_from:
     * 'next_billing_period', confirmed against the installed Cashier
     * source, not assumed from the previous processor's shape). This is
     * NOT an immediate cancellation: `status` stays whatever it already
     * is (`active`) through the notice window — Paddle writes `ends_at`
     * to the scheduled effective date, not `status`
     * (PaddleWebhookController's STATUS_MAP docblock covers why the
     * webhook side needs no ends_at-based inference the way the previous
     * processor did). The tenant keeps full access until the real
     * `subscription.canceled` webhook fires once that date arrives.
     *
     * `true` calls Subscription::stopCancelation() — the real "undo a
     * scheduled cancellation" method (PATCH /subscriptions/{id} with
     * scheduled_change: null). Deliberately NOT resume(): that method
     * undoes a *pause*, a completely different Paddle mechanism with its
     * own endpoint — calling it here would be assuming Paddle's shape
     * mirrors the previous processor's single "resume" concept, which it
     * doesn't. A paused or already-canceled subscription has no
     * "undo a scheduled cancel" to perform, so those get the same clean
     * 422 (`subscription_ended`) the previous processor's `resume()`-
     * throws-on-expired case did, not a 500 or a wrong API call.
     *
     * Both directions are idempotent against the subscription's *current*
     * Paddle state (`autoRenews()`) rather than always placing an
     * outbound call — toggling to the state it's already in never hits
     * Paddle at all, so it can't fail even without a working API key.
     */
    public function update(UpdateAutoRenewRequest $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $subscription = $request->user()->subscription('default');

        if ($subscription === null) {
            return response()->json([
                'error' => 'no_billing_account',
                'message' => 'You don\'t have a billing account yet. Subscribe to a plan first.',
                'fields' => null,
            ], 422);
        }

        $autoRenew = $request->boolean('auto_renew');

        if ($autoRenew === $subscription->autoRenews()) {
            return response()->json(['data' => $this->subscriptionPayload($tenant, $subscription)]);
        }

        if ($autoRenew && ($subscription->paused() || $subscription->canceled())) {
            return response()->json([
                'error' => 'subscription_ended',
                'message' => 'This subscription has already ended and can\'t be resumed — subscribe again to keep sending review requests.',
                'fields' => null,
            ], 422);
        }

        try {
            if ($autoRenew) {
                $subscription->stopCancelation();
            } else {
                $subscription->cancel(false);
            }
        } catch (\Throwable) {
            return response()->json([
                'error' => 'billing_unavailable',
                'message' => 'Paddle isn\'t available right now. Try again shortly.',
                'fields' => null,
            ], 502);
        }

        return response()->json(['data' => $this->subscriptionPayload($tenant, $subscription->fresh())]);
    }

    private function subscriptionPayload(Tenant $tenant, $subscription): array
    {
        return [
            'plan' => $tenant->plan,
            'billing_interval' => $tenant->billing_interval,
            'status' => $tenant->status,
            'auto_renew' => $subscription?->autoRenews(),
            'current_period_end' => $subscription?->periodEnd(),
            'ends_at' => $subscription?->ends_at,
            'trial_ends_at' => $tenant->trial_ends_at,
        ];
    }
}
