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
 * `intervals.*.price` is the Paddle Price ID (format `pri_...`, from the
 * Paddle dashboard's Monthly/Annual prices under this app's one Product)
 * each interval resolves to server-side — the only thing a client's
 * POST /subscribe `interval` value ever resolves to. A client can never
 * submit a raw Paddle price ID directly (.claude/SECURITY.md #1: never
 * trust client input for anything that decides what gets charged), same
 * pattern the old per-plan stripe_price/Lemon Squeezy variant lookup
 * used. Neither price should have a free trial configured on the Paddle
 * side — the trial lives entirely in `tenants` (BILLING.md's "no billing
 * object exists before actual conversion" rule, unchanged by the
 * processor swap).
 *
 * requests_per_minute is flat now that there's one plan (previously scaled
 * 60/300/1000 across three tiers) — inherited from the old middle "growth"
 * tier's value as a reasonable default, not a re-derived capacity-planning
 * decision. Flag before relying on it for real capacity planning, same
 * caveat the original three-tier file carried.
 *
 * price_cents: the same $20/$200 confirmed here and in the actual
 * checkout, in a computable form — used by PaddleWebhookController's
 * billing_interval sync isn't needed (Paddle's own price_id on the
 * subscription item is matched against `intervals.*.price` directly,
 * never against a dollar figure) but kept for any future MRR/ARR
 * reporting the same way the pre-removal AdminBillingController used it.
 */
return [
    'standard' => [
        'name' => 'ReviewEngine',

        // Display only — never used to compute what's actually charged.
        // The real amount is whatever the Paddle price is configured for.
        'price_was_display' => '$25',
        'requests_per_minute' => 300,

        'intervals' => [
            'monthly' => [
                'price' => env('PADDLE_PRICE_MONTHLY', 'pri_monthly_placeholder'),
                'price_display' => '$20/mo',
                'price_cents' => 2000,
            ],

            'annual' => [
                'price' => env('PADDLE_PRICE_ANNUAL', 'pri_annual_placeholder'),
                'price_display' => '$200/yr',
                'savings_display' => '2 months free',
                'price_cents' => 20000,
            ],
        ],
    ],
];
