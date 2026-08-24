<?php

/**
 * The single plan (.claude/BILLING.md's pricing change — replaces the old
 * Starter/Growth/Pro three tiers). Still keyed by a plan name ('standard')
 * even though there's only one now — StartTrialRequest/Tenant::startTrial()/
 * tenants.plan all already validate and store a plan *key*, not a plan
 * object, and every existing test that seeds a tenant's plan
 * (OnboardingStatusTest, TrialExpiryDataIntegrityTest, etc.) does the
 * same — keeping that shape means none of that machinery needed to change
 * for a pricing-model change alone.
 *
 * `intervals.*.variant` (the Lemon Squeezy Variant ID each interval
 * resolved to server-side — never a raw ID a client could submit
 * directly, .claude/SECURITY.md #1) was removed along with the Lemon
 * Squeezy package and SubscriptionController. Whatever replaces it
 * (Paddle Price IDs) needs the same shape: a server-side-only lookup
 * keyed by `interval`, never a client-supplied processor ID.
 *
 * requests_per_minute is flat now that there's one plan (previously scaled
 * 60/300/1000 across three tiers) — inherited from the old middle "growth"
 * tier's value as a reasonable default, not a re-derived capacity-planning
 * decision. Flag before relying on it for real capacity planning, same
 * caveat the original three-tier file carried.
 *
 * price_cents (added for the now-removed AdminBillingController's MRR/ARR
 * breakdown, still used by SendRenewalReminders... — also removed, see
 * git history): the same $20/$200 confirmed here and in the actual
 * checkout, in a computable form. This is the one place in the app that
 * multiplies a subscription count by a dollar figure — every other price
 * reference stays the display-only strings above, which is why this
 * wasn't needed until now. Kept here (provider-agnostic display data)
 * even though its only current readers were removed with Lemon Squeezy —
 * real prices for a Paddle rebuild.
 */
return [
    'standard' => [
        'name' => 'ReviewEngine',

        // Display only — never used to compute what's actually charged.
        // The real amount is whatever the payment processor's price/plan
        // object is priced at.
        'price_was_display' => '$25',
        'requests_per_minute' => 300,

        'intervals' => [
            'monthly' => [
                'price_display' => '$20/mo',
                'price_cents' => 2000,
            ],

            'annual' => [
                'price_display' => '$200/yr',
                'savings_display' => '2 months free',
                'price_cents' => 20000,
            ],
        ],
    ],
];
