<?php

/**
 * The single plan (.claude/BILLING.md's pricing change — replaces the old
 * Starter/Growth/Pro three tiers). Still keyed by a plan name ('standard')
 * even though there's only one now — StartTrialRequest/SubscribeRequest/
 * Tenant::startTrial()/tenants.plan all already validate and store a plan
 * *key*, not a plan object, and every existing test that seeds a tenant's
 * plan (OnboardingStatusTest, TrialExpiryDataIntegrityTest, etc.) does the
 * same — keeping that shape means none of that machinery needed to change
 * for a pricing-model change alone.
 *
 * `intervals.*.variant` is the only thing a client's POST /subscribe
 * `interval` value resolves to server-side — a client can never submit a
 * raw Lemon Squeezy variant ID directly (.claude/SECURITY.md #1: never
 * trust client input for anything that decides what gets charged), same
 * pattern the old per-plan stripe_price lookup used.
 *
 * The LEMON_SQUEEZY_VARIANT_* values are placeholders until real Lemon
 * Squeezy test-mode Variant objects exist — see BILLING.md for what's
 * still needed before these can be real.
 *
 * requests_per_minute is flat now that there's one plan (previously scaled
 * 60/300/1000 across three tiers) — inherited from the old middle "growth"
 * tier's value as a reasonable default, not a re-derived capacity-planning
 * decision. Flag before relying on it for real capacity planning, same
 * caveat the original three-tier file carried.
 *
 * price_cents (added for AdminBillingController's MRR/ARR breakdown): the
 * same $20/$200 confirmed here and in the actual checkout, in a computable
 * form. This is the one place in the app that multiplies a subscription
 * count by a dollar figure — every other price reference stays the
 * display-only strings above, which is why this wasn't needed until now.
 */
return [
    'standard' => [
        'name' => 'ReviewEngine',

        // Display only — never used to compute what's actually charged.
        // The real amount is whatever the Lemon Squeezy variant is priced at.
        'price_was_display' => '$25',
        'requests_per_minute' => 300,

        'intervals' => [
            'monthly' => [
                'variant' => env('LEMON_SQUEEZY_VARIANT_MONTHLY', 'variant_monthly_placeholder'),
                'price_display' => '$20/mo',
                'price_cents' => 2000,
            ],

            'annual' => [
                'variant' => env('LEMON_SQUEEZY_VARIANT_ANNUAL', 'variant_annual_placeholder'),
                'price_display' => '$200/yr',
                'savings_display' => '2 months free',
                'price_cents' => 20000,
            ],
        ],
    ],
];
