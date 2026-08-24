<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'quick_add_token',
        'gbp_step_done',
        'contacts_step_done',
        'onboarding_completed_at',
        'plan',
        'status',
        'billing_interval',
        'trial_started_at',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'gbp_step_done' => 'boolean',
            'contacts_step_done' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Sets the free trial in motion — no card, no Stripe call (the
     * Phase decision this whole feature is built on: trial state lives
     * entirely here, not in a Stripe Customer/Subscription). Called once,
     * from onboarding's plan step.
     */
    public function startTrial(string $plan, int $days = 7): void
    {
        $this->plan = $plan;
        $this->status = 'trialing';
        $this->trial_started_at = now();
        $this->trial_ends_at = now()->addDays($days);
        $this->save();
    }

    /**
     * The single gate the sending-side middleware and drip:release-pending
     * both check — a thin bool wrapper around sendingBlockedReason() below,
     * for the one caller (drip:release-pending's defense-in-depth skip)
     * that only needs to know whether to skip, not why.
     */
    public function sendingBlocked(): bool
    {
        return $this->sendingBlockedReason() !== null;
    }

    /**
     * Three independent triggers land on the same "access ends, data
     * doesn't" outcome (.claude/BILLING.md's "Auto-renew toggle" section
     * for the first two; the "add email verification" decision doc for
     * the third) — kept as one method, not three separate checks
     * scattered across callers, so RequireSendingAccess's error
     * code/message and this list can never drift apart:
     *  - 'trial_expired': trial_ends_at passed with no paid subscription
     *    ever created.
     *  - 'subscription_ended': a paid subscription stopped renewing —
     *    Paddle reports the subscription 'canceled' or 'paused', which
     *    PaddleWebhookController's STATUS_MAP only ever writes as
     *    'canceled' once the subscription has actually ended (Paddle
     *    itself keeps a scheduled cancel-at-period-end's own status at
     *    'active' through its notice window — see that controller's
     *    STATUS_MAP docblock for why this needs no ends_at-based
     *    heuristic the way the previous processor's mapping did).
     *  - 'email_unverified': the tenant OWNER (not whichever team member
     *    happens to be acting — see ownerEmailVerified()'s own docblock
     *    for why) hasn't clicked their signed verification link yet.
     *    Checked last, deliberately: a trial_expired/canceled tenant sees
     *    that message, not this one — verifying email alone wouldn't
     *    unblock them anyway, so it's not the more actionable reason to
     *    surface first.
     * 'pending' (never picked a plan) can't reach these endpoints in
     * practice since onboarding requires a plan first, and there's no
     * reason to special-case it here too.
     *
     * 'past_due' deliberately has NO branch here — a failed payment
     * (PaddleWebhookController's handleTransactionPaymentFailed, or a
     * subscription.updated reporting Paddle's own status='past_due'
     * during its dunning retry window) does NOT block sending access.
     * Confirmed decision, not an oversight: blocking on the first failed
     * attempt would be more aggressive than anything this app has ever
     * done for a temporarily-failed-but-recoverable paying customer —
     * same reasoning the pre-Paddle design already applied when past_due
     * was a silent no-op, just now the state itself is real and visible
     * (TenantController's payload, BillingSection) instead of hidden.
     * Access only actually cuts off once Paddle exhausts dunning and the
     * subscription genuinely reaches 'canceled'.
     */
    public function sendingBlockedReason(): ?string
    {
        if ($this->status === 'trial_expired') {
            return 'trial_expired';
        }

        if ($this->status === 'canceled') {
            return 'subscription_ended';
        }

        if (! $this->ownerEmailVerified()) {
            return 'email_unverified';
        }

        return null;
    }

    /**
     * Deliberately the OWNER's verification, not whichever user's Sanctum
     * token authenticated the current request: email verification here is
     * an account-integrity fact about the tenant as a whole (the same
     * "owner-scoped, not per-member" bucket billing/subscription state
     * already lives in — .claude/BILLING.md: "Billing is owner-only —
     * never grantable to a member"), not a per-member permission. It also
     * has to work for POST /quick/{token} (RequireSendingAccess's public,
     * unauthenticated entry point — ResolveQuickAddTenant sets tenant
     * context from the URL token alone, no Sanctum user exists at all),
     * so any acting-user-scoped check couldn't apply there uniformly
     * anyway. Team-invited members never go through their own email
     * verification step (TeamInviteController::accept() never touches
     * email_verified_at) — verification is purely a registration-time,
     * owner-level concept in this app, consistent with that.
     */
    private function ownerEmailVerified(): bool
    {
        return User::query()
            ->where('tenant_id', $this->id)
            ->where('role', 'owner')
            ->value('email_verified_at') !== null;
    }

    protected static function booted(): void
    {
        // Every tenant needs a quick-add token from the moment it exists
        // — an explicit assignment (unlikely, but matching
        // BelongsToTenant's own "explicit always wins" convention) is
        // never overwritten.
        static::creating(function (self $tenant): void {
            if (empty($tenant->quick_add_token)) {
                $tenant->quick_add_token = Str::random(48);
            }
        });
    }
}
