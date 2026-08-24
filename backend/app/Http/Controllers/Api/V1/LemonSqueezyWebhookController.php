<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LemonSqueezy\Laravel\Http\Controllers\WebhookController as PackageWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps (never extends — the package's own WebhookController is `final`)
 * lemonsqueezy/laravel's webhook processing to solve a problem Stripe's
 * synchronous charge flow never had to: an incoming Lemon Squeezy webhook
 * carries no Sanctum bearer token, so SetTenantContext never runs for it —
 * app.current_tenant_id is unset, and every tenant table's FORCE ROW LEVEL
 * SECURITY policy means the package's own writes (creating/syncing a row
 * in lemon_squeezy_subscriptions, looking up the billable User) would
 * silently match zero rows instead of the real ones.
 *
 * The fix: resolve tenant_id from meta.custom_data.tenant_id — set at
 * checkout time by SubscriptionController::subscribe's
 * $user->subscribe($variant, custom: ['tenant_id' => ...]) call, and
 * confirmed (docs.lemonsqueezy.com/help/checkout/passing-custom-data) to
 * round-trip into every Order/Subscription/License-key webhook tied to
 * that checkout, not just the first one — so this resolves correctly for
 * subscription_updated/cancelled/resumed/expired/paused/unpaused too, not
 * only subscription_created. Set the same set_config() + CurrentTenant
 * pair SetTenantContext uses for a normal request, *before* delegating to
 * the package's own controller instance.
 *
 * Signature verification happens as route middleware
 * (LemonSqueezy\Laravel\Http\Middleware\VerifyWebhookSignature, applied
 * unconditionally in routes/api.php — same "fail closed if the secret is
 * misconfigured" reasoning StripeWebhookController used to apply), not
 * here — by the time __invoke runs, the request is already known-genuine.
 *
 * tenant.status/billing_interval sync (the actual point of this
 * controller, beyond just making the package's own tables work) happens
 * *after* delegating, and only once the inner controller's response
 * confirms the event was actually handled successfully — a malformed or
 * rejected payload never partially updates tenant state.
 */
class LemonSqueezyWebhookController extends Controller
{
    /**
     * meta.custom_data.tenant_id -> our 5-state tenant.status machine
     * (.claude/BILLING.md — reused exactly, not reinvented). Keyed on
     * Lemon Squeezy's actual subscription status
     * (data.attributes.status), not on meta.event_name: every
     * subscription_* event carries the subscription's current status in
     * its own payload, so mapping off status once covers
     * created/updated/cancelled/resumed/expired/paused/unpaused uniformly
     * — including edge cases a name-per-event switch could miss.
     *
     * null = deliberate no-op, tenant.status stays whatever it already
     * was:
     *  - past_due/unpaid: a failed renewal starts Lemon Squeezy's own
     *    dunning retry window. Blocking sending access on the first
     *    failed attempt would be more aggressive than anything this app
     *    has ever done (dunning/payment-failure handling was never built
     *    for Stripe either) — confirmed decision, not an oversight.
     *  - on_trial: should never actually occur — our trial lives entirely
     *    in tenants.trial_started_at/trial_ends_at (.claude/BILLING.md's
     *    "no billing object exists before actual conversion" rule), so no
     *    Lemon Squeezy variant should ever have its own trial configured.
     *    If this ever fires, treating it as a no-op is the safe default —
     *    never silently grant 'active' access for a status that isn't
     *    really "paying."
     *  - cancelled: handled separately below, NOT in this table — see
     *    syncTenantStatus(). Whether it's a no-op or maps to 'canceled'
     *    depends on ends_at (the auto-renew toggle's whole point:
     *    cancel-at-period-end must not cut off access early).
     */
    private const STATUS_MAP = [
        'active' => 'active',
        'expired' => 'canceled',
        'paused' => 'canceled',
    ];

    public function __construct(private readonly PackageWebhookController $inner) {}

    public function __invoke(Request $request): Response
    {
        $tenantId = $request->input('meta.custom_data.tenant_id');

        if (! is_string($tenantId) || ! Str::isUuid($tenantId)) {
            // Not every Lemon Squeezy event is tied to one of our checkouts
            // (customer_updated, affiliate_activated carry no custom_data
            // at all) — those never touch a tenant-scoped table, so no
            // context is needed and the package's own controller handles
            // them (or no-ops on "no handler found") exactly as normal.
            return $this->inner->__invoke($request);
        }

        return DB::transaction(function () use ($request, $tenantId) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
            app(CurrentTenant::class)->set($tenantId);

            try {
                $response = $this->inner->__invoke($request);

                if ($response->getStatusCode() < 300) {
                    $this->syncTenantStatus($request, $tenantId);
                }

                return $response;
            } finally {
                app(CurrentTenant::class)->clear();
            }
        });
    }

    private function syncTenantStatus(Request $request, string $tenantId): void
    {
        // order_created, license_key_created etc. share the same
        // custom_data but describe a different object entirely —
        // data.type is the JSON:API discriminator that confirms this
        // payload is actually a Subscription before any status mapping is
        // attempted.
        if ($request->input('data.type') !== 'subscriptions') {
            return;
        }

        $lemonSqueezyStatus = $request->input('data.attributes.status');
        $mappedStatus = $lemonSqueezyStatus === 'cancelled'
            ? $this->mappedStatusForCancelled($request)
            : self::STATUS_MAP[$lemonSqueezyStatus] ?? null;

        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            return;
        }

        $attributes = [];

        if ($mappedStatus !== null) {
            $attributes['status'] = $mappedStatus;
        }

        $variantId = $request->input('data.attributes.variant_id');
        $interval = $this->intervalForVariant($variantId !== null ? (string) $variantId : null);

        if ($interval !== null) {
            $attributes['billing_interval'] = $interval;
        }

        if ($attributes !== []) {
            $tenant->update($attributes);
        }
    }

    /**
     * cancel-at-period-end (Settings/Billing's auto-renew toggle, or a
     * cancellation from Lemon Squeezy's own customer portal — same
     * status either way) must NOT cut the tenant off immediately.
     * data.attributes.ends_at is the billing period's real end: a future
     * ends_at means the subscription is only in its grace period
     * (LemonSqueezy\Laravel\Subscription::onGracePeriod() — cancelled()
     * && ends_at->isFuture()), so tenant.status stays whatever it already
     * is (should be 'active') and access continues. Lemon Squeezy sends a
     * separate subscription_expired webhook (mapped to 'canceled' above)
     * once that date actually arrives — that's the real trigger, not this
     * event. A null or already-past ends_at (shouldn't happen for a
     * normal cancel-at-period-end call, but a hard/immediate cancellation
     * would look like this) falls back to canceling right away rather
     * than silently granting extra access.
     */
    private function mappedStatusForCancelled(Request $request): ?string
    {
        $endsAt = $request->input('data.attributes.ends_at');

        if ($endsAt !== null && Carbon::parse($endsAt)->isFuture()) {
            return null;
        }

        return 'canceled';
    }

    private function intervalForVariant(?string $variantId): ?string
    {
        if ($variantId === null) {
            return null;
        }

        foreach (config('plans.standard.intervals') as $interval => $intervalConfig) {
            if ((string) $intervalConfig['variant'] === $variantId) {
                return $interval;
            }
        }

        return null;
    }
}
