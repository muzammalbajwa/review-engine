<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extends (not wraps — unlike the previous processor's controller, this
 * one isn't `final`) laravel/cashier-paddle's own WebhookController to
 * solve the same problem every processor before it has had: an incoming
 * Paddle webhook carries no Sanctum bearer token, so SetTenantContext
 * never runs for it — app.current_tenant_id is unset, and every
 * cashier-paddle table's FORCE ROW LEVEL SECURITY policy means Cashier's
 * own writes (creating/updating customers/subscriptions/
 * subscription_items/transactions rows) would silently match zero rows
 * instead of the real ones.
 *
 * Resolution mechanism is deliberately NOT custom_data (unlike the
 * previous processor, which had no other option — Lemon Squeezy's
 * checkout never created a local row before the webhook arrived). Paddle
 * checkouts DO create a local Customer row synchronously, before the
 * overlay even opens (Billable::checkout()'s createAsCustomer() call —
 * see SubscriptionController::subscribe()), with the correct tenant_id
 * already stamped via BelongsToTenant. Every Paddle webhook this app
 * handles carries a customer_id (or, for customer.updated, is itself
 * keyed by that id) — resolving tenant_id by looking that Customer row
 * up is available on literally every event, not conditional on Paddle's
 * custom_data propagation guarantees. custom_data.tenant_id (also set at
 * checkout time) is still passed through and cross-checked as defense in
 * depth, never trusted alone (.claude/SECURITY.md #1).
 *
 * Signature verification happens as route middleware
 * (Laravel\Paddle\Http\Middleware\VerifyWebhookSignature, applied
 * unconditionally in routes/api.php — same "fail closed if the secret is
 * misconfigured" reasoning every processor's webhook route has used here;
 * Cashier's own WebhookController constructor only attaches this
 * middleware when cashier.webhook_secret happens to be truthy, which
 * would silently accept any payload unverified if that config were ever
 * blank) — by the time __invoke runs, the request is already
 * known-genuine.
 *
 * tenant.status sync happens *after* delegating to Cashier's own
 * processing, and only once the inner response confirms the event was
 * actually handled successfully — a malformed or rejected payload never
 * partially updates tenant state.
 */
class PaddleWebhookController extends CashierWebhookController
{
    /**
     * Paddle's own subscription status vocabulary
     * (Laravel\Paddle\Subscription::STATUS_*) mapped onto this app's
     * status machine (.claude/BILLING.md — reused exactly, not
     * reinvented, now with `past_due` promoted to a real state per the
     * dunning design rather than the previous processor's no-op
     * treatment).
     *
     * Unlike the previous processor, this needs no ends_at-based
     * "is this actually still in its grace period" heuristic
     * (mappedStatusForCancelled() there) — Paddle's own `status` field
     * already stays 'active' through a scheduled cancel-at-period-end's
     * notice window (data.scheduled_change, not a status change) and
     * only flips to 'canceled' once the subscription actually ends. The
     * status column read fresh after Cashier's own handler has already
     * written it is the direct, sufficient source of truth.
     *
     * 'trialing' deliberately has no entry — should never actually occur
     * (.claude/BILLING.md's "no billing object before real conversion"
     * rule: no Paddle price has a trial configured). If it ever does,
     * having no mapping is the safe default — never silently grant
     * 'active' for a status that isn't really "paying."
     */
    private const STATUS_MAP = [
        Subscription::STATUS_ACTIVE => 'active',
        Subscription::STATUS_PAST_DUE => 'past_due',
        Subscription::STATUS_PAUSED => 'canceled',
        Subscription::STATUS_CANCELED => 'canceled',
    ];

    public function __invoke(Request $request): Response
    {
        $payload = $request->all();
        $customerId = $this->customerIdFromPayload($payload);

        if ($customerId === null) {
            // Not every Paddle event is tied to a customer this app
            // created (or is a shape this app doesn't recognize at all)
            // — those never touch a tenant-scoped table, so no context is
            // needed and Cashier's own controller handles them (or
            // no-ops on "no handler found") exactly as normal.
            return parent::__invoke($request);
        }

        $tenantId = $this->resolveTenantId($customerId);

        if ($tenantId === null) {
            // A Paddle customer id this app has no Customer row for at
            // all — shouldn't happen (every Paddle customer we have was
            // created by our own checkout flow first), but fails safe:
            // logged, then handled with no tenant context rather than a
            // 500. Cashier's own writes would silently match zero rows
            // under RLS regardless.
            Log::warning('paddle webhook: no Customer row found for paddle_id', [
                'paddle_customer_id' => $customerId,
                'event_type' => $payload['event_type'] ?? null,
            ]);

            return parent::__invoke($request);
        }

        $this->assertCustomDataTenantMatches($payload, $tenantId);

        return DB::transaction(function () use ($request, $payload, $tenantId) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);
            app(CurrentTenant::class)->set($tenantId);

            try {
                $response = parent::__invoke($request);

                if ($response->getStatusCode() < 300) {
                    $this->syncTenantStatus($payload, $tenantId);
                }

                return $response;
            } finally {
                app(CurrentTenant::class)->clear();
            }
        });
    }

    /**
     * Cashier's base WebhookController has NO handler for this event at
     * all — 'transaction.payment_failed' isn't in its dynamic-dispatch
     * method list (verified against the installed package source), so
     * without this override it silently no-ops and returns an empty 200,
     * never touching tenant.status. Added per this task's explicit
     * requirement: a failed transaction must move tenant.status to
     * 'past_due' immediately, and must never collapse into
     * 'trial_expired' or 'canceled'.
     *
     * onlyFrom ['active', 'past_due']: never pulls a 'trial_expired',
     * 'canceled', 'trialing', or 'pending' tenant INTO past_due from a
     * stray/late transaction retry against a subscription that has
     * already really ended — past_due only ever means "was paying,
     * temporarily isn't."
     *
     * Deliberately does not create a Transaction row the way
     * handleTransactionCompleted does — this app doesn't track failed
     * attempts as first-class Transaction records (that table's own
     * status vocabulary — draft/ready/billed/paid/completed/canceled/
     * past_due — assumes a real charge object, and Cashier's base
     * controller never persists one for a failure either); the audit
     * trail for this event is the Log::info call below plus whatever
     * Paddle's own dashboard already records.
     */
    protected function handleTransactionPaymentFailed(array $payload): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId === null) {
            return;
        }

        $this->applyStatus($tenantId, 'past_due', onlyFrom: ['active', 'past_due']);

        Log::info('paddle webhook: transaction.payment_failed', [
            'tenant_id' => $tenantId,
            'paddle_transaction_id' => $payload['data']['id'] ?? null,
            'paddle_subscription_id' => $payload['data']['subscription_id'] ?? null,
        ]);
    }

    private function customerIdFromPayload(array $payload): ?string
    {
        $data = $payload['data'] ?? [];

        // customer.updated is keyed by the customer's own id, not a
        // customer_id field on some other object.
        if (($payload['event_type'] ?? null) === 'customer.updated') {
            return is_string($data['id'] ?? null) ? $data['id'] : null;
        }

        return is_string($data['customer_id'] ?? null) ? $data['customer_id'] : null;
    }

    private function resolveTenantId(string $paddleCustomerId): ?string
    {
        return DB::transaction(function () use ($paddleCustomerId) {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            return Customer::withoutGlobalScopes()->where('paddle_id', $paddleCustomerId)->value('tenant_id');
        });
    }

    private function assertCustomDataTenantMatches(array $payload, string $resolvedTenantId): void
    {
        $customDataTenantId = $payload['data']['custom_data']['tenant_id'] ?? null;

        if ($customDataTenantId !== null && $customDataTenantId !== $resolvedTenantId) {
            Log::error("paddle webhook: custom_data.tenant_id doesn't match the Customer row's own tenant_id", [
                'resolved_tenant_id' => $resolvedTenantId,
                'custom_data_tenant_id' => $customDataTenantId,
                'event_type' => $payload['event_type'] ?? null,
            ]);
        }
    }

    /**
     * data.type discriminator equivalent: subscription.* events carry the
     * subscription's own paddle_id at data.id; transaction.* events carry
     * it at data.subscription_id (nullable for a one-off transaction —
     * never null for this app, which only ever sells subscriptions).
     * Reused for subscription.created/updated/canceled/paused AND
     * transaction.completed (covers "recovered from past_due once a
     * retried transaction succeeds" even if a subscription.updated event
     * hasn't landed yet) — every one of them ends in "read the
     * subscription's current status, map it."
     *
     * Deliberately excludes transaction.payment_failed: that event has
     * its own dedicated handler (handleTransactionPaymentFailed) which
     * sets 'past_due' directly, precisely BECAUSE a single failed
     * attempt doesn't necessarily mean Paddle has updated the
     * subscription's own `status` column yet — reading it here on that
     * same event would silently re-read the still-'active' status and
     * clobber the past_due signal right back, defeating the entire
     * point of handling that event at all. Live-reproduced against this
     * exact controller's own tests, not a hypothetical.
     */
    private function syncTenantStatus(array $payload, string $tenantId): void
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        $data = $payload['data'] ?? [];

        if ($eventType === 'transaction.payment_failed') {
            return;
        }

        $subscriptionId = match (true) {
            str_starts_with($eventType, 'subscription.') => $data['id'] ?? null,
            str_starts_with($eventType, 'transaction.') => $data['subscription_id'] ?? null,
            default => null,
        };

        if (! is_string($subscriptionId)) {
            return;
        }

        $subscription = Subscription::query()->where('paddle_id', $subscriptionId)->first();

        if ($subscription === null) {
            return;
        }

        $mappedStatus = self::STATUS_MAP[$subscription->status] ?? null;

        if ($mappedStatus !== null) {
            $this->applyStatus($tenantId, $mappedStatus);
        }

        $interval = $this->intervalForSubscription($subscription);

        if ($interval !== null) {
            Tenant::query()->where('id', $tenantId)->update(['billing_interval' => $interval]);
        }
    }

    /**
     * @param  list<string>|null  $onlyFrom  Restrict the write to firing
     *                                        only when tenant.status is
     *                                        currently one of these — see
     *                                        handleTransactionPaymentFailed's
     *                                        own docblock for why that
     *                                        caller needs this and the
     *                                        generic syncTenantStatus()
     *                                        path doesn't (a subscription
     *                                        row's own status is already
     *                                        authoritative there).
     */
    private function applyStatus(string $tenantId, string $status, ?array $onlyFrom = null): void
    {
        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            return;
        }

        if ($onlyFrom !== null && ! in_array($tenant->status, $onlyFrom, true)) {
            return;
        }

        if ($tenant->status !== $status) {
            $tenant->status = $status;
            $tenant->save();
        }
    }

    private function intervalForSubscription(Subscription $subscription): ?string
    {
        $priceId = $subscription->items()->value('price_id');

        if ($priceId === null) {
            return null;
        }

        foreach (config('plans.standard.intervals') as $interval => $intervalConfig) {
            if ((string) $intervalConfig['price'] === $priceId) {
                return $interval;
            }
        }

        return null;
    }
}
