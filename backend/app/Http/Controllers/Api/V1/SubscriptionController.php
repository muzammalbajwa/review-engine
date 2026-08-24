<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscribeRequest;
use App\Http\Requests\Billing\UpdateAutoRenewRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LemonSqueezy\Laravel\Exceptions\InvalidCustomer;
use LogicException;

class SubscriptionController extends Controller
{
    /**
     * The conversion flow (Settings/Billing) for a trialing or
     * trial_expired tenant — also reachable by a tenant who wants to skip
     * the trial and pay immediately, from onboarding.
     *
     * Unlike the old Stripe flow, this does NOT create a real subscription
     * or flip tenant.status synchronously — Lemon Squeezy has no
     * server-side "charge this payment method now" API, only hosted
     * checkout (.claude/BILLING.md). This creates a Checkout for the
     * chosen interval and returns its URL; the frontend redirects there.
     * Activation happens asynchronously: once the customer pays, Lemon
     * Squeezy sends a subscription_created webhook, and
     * LemonSqueezyWebhookController is what actually sets
     * tenant.status = 'active' and tenant.billing_interval.
     *
     * custom: ['tenant_id' => ...] round-trips through the checkout into
     * that webhook's meta.custom_data — the only way an incoming webhook
     * (no bearer token, no authenticated session) knows which tenant to
     * activate. See LemonSqueezyWebhookController's docblock for the RLS
     * mechanics this depends on.
     *
     * Same "honest failure state, not a generic 500" treatment as
     * portal() below — checkout creation is a real outbound Lemon Squeezy
     * API call (Checkout::url()) that throws MissingStore when
     * LEMON_SQUEEZY_STORE isn't configured, or a plain Exception/
     * LemonSqueezyApiError for a missing API key or an unreachable/
     * misconfigured Lemon Squeezy — live-confirmed against this exact
     * environment (no real credentials configured yet, see BILLING.md),
     * not a hypothetical.
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $variant = config("plans.standard.intervals.{$data['interval']}.variant");
        $tenant = $request->user()->tenant;

        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? '', '/');

        try {
            $url = $request->user()
                ->subscribe($variant, custom: ['tenant_id' => $tenant->id])
                ->redirectTo("{$frontendUrl}/settings")
                ->url();
        } catch (\Throwable) {
            return response()->json([
                'error' => 'billing_unavailable',
                'message' => 'Checkout isn\'t available right now. Try again shortly.',
                'fields' => null,
            ], 502);
        }

        return response()->json(['data' => ['checkout_url' => $url]], 201);
    }

    /**
     * Settings/Billing's one read of "where does this tenant stand."
     * tenant.status/billing_interval are the authoritative source (they're
     * the only fields that mean anything during trialing/trial_expired,
     * when no Subscription row exists at all) — a real subscription, when
     * one exists, only adds `ends_at` (the one fact tenant.status doesn't
     * carry: a cancellation already scheduled for the end of the billing
     * period).
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $subscription = $request->user()->subscription('default');

        return response()->json(['data' => $this->subscriptionPayload($tenant, $subscription)]);
    }

    /**
     * .claude/API.md doesn't name this one explicitly, but /settings'
     * billing section needs it: a real Lemon Squeezy customer portal URL
     * via Billable::customerPortalUrl(), never a link the frontend builds
     * itself (.claude/SECURITY.md #5: the API key that call needs stays
     * server-side). Two distinct, honest failure states rather than one
     * generic 500: never subscribed yet (no Lemon Squeezy customer exists
     * to open a portal for) vs. Lemon Squeezy itself being
     * unreachable/misconfigured (no API key in this dev environment, same
     * gap as SubscribeRequest's checkout flow).
     */
    public function portal(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->customer === null || $user->customer->lemon_squeezy_id === null) {
            return response()->json([
                'error' => 'no_billing_account',
                'message' => 'You don\'t have a billing account yet. Subscribe to a plan first.',
                'fields' => null,
            ], 422);
        }

        try {
            $url = $user->customerPortalUrl();
        } catch (InvalidCustomer) {
            return response()->json([
                'error' => 'no_billing_account',
                'message' => 'You don\'t have a billing account yet. Subscribe to a plan first.',
                'fields' => null,
            ], 422);
        } catch (\Throwable) {
            return response()->json([
                'error' => 'billing_unavailable',
                'message' => 'The billing portal isn\'t available right now. Try again shortly.',
                'fields' => null,
            ], 502);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * Settings/Billing's auto-renew toggle. `false` calls Lemon Squeezy's
     * cancel-at-period-end (App\Models\Subscription::cancel(), a DELETE
     * against their API) — NOT an immediate cancellation. The tenant keeps
     * full access until the subscription's `ends_at`; tenant.status only
     * flips to 'canceled' once Lemon Squeezy's own subscription_expired
     * webhook fires at that date (LemonSqueezyWebhookController — see
     * .claude/BILLING.md's "Auto-renew toggle" section). `true` calls
     * resume() (a PATCH with cancelled: false — Lemon Squeezy's actual
     * resume mechanism; nothing here assumes Stripe's shape carries over).
     *
     * Both directions are idempotent against the subscription's current
     * state rather than always making an outbound call — flipping a
     * toggle that's already in the requested position shouldn't place a
     * real API call and shouldn't 502 if Lemon Squeezy happens to be
     * unreachable at that exact moment.
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

        try {
            if ($autoRenew) {
                $subscription->resume();
            } else {
                $subscription->cancel();
            }
        } catch (LogicException) {
            // Subscription::resume() throws this itself when the
            // subscription is already expired — Lemon Squeezy has no
            // "resume after the grace period ended" operation, the same
            // way Stripe never had one either. A real, actionable error,
            // not a generic 500.
            return response()->json([
                'error' => 'subscription_ended',
                'message' => 'This subscription has already ended and can\'t be resumed — subscribe again to keep sending review requests.',
                'fields' => null,
            ], 422);
        } catch (\Throwable) {
            return response()->json([
                'error' => 'billing_unavailable',
                'message' => 'Lemon Squeezy isn\'t available right now. Try again shortly.',
                'fields' => null,
            ], 502);
        }

        return response()->json(['data' => $this->subscriptionPayload($tenant, $subscription->fresh())]);
    }

    /**
     * tenant.status/plan/billing_interval/trial_ends_at are authoritative
     * (they're the only fields that mean anything during
     * trialing/trial_expired, when no Subscription row exists at all) —
     * $subscription only ever contributes the facts the tenant row
     * doesn't carry: whether it's actually going to renew, and the
     * relevant date for that (renews_at while auto-renewing, ends_at once
     * cancel-at-period-end has been requested — App\Models\Subscription's
     * autoRenews()/periodEnd()). `ends_at` stays in the payload too,
     * unchanged, for any existing caller still reading it directly.
     */
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
