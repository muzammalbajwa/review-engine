<?php

/**
 * The 3 plans (.claude/ROADMAP.md Phase 1 Step "Stripe Cashier + 3 plans").
 * Keys here are the only values a client may send to POST /subscribe — the
 * server resolves the actual Stripe price ID server-side. A client can never
 * submit a raw Stripe price ID directly (.claude/SECURITY.md #1: never trust
 * client input for anything that decides what gets charged).
 *
 * The STRIPE_PRICE_* values are placeholders until real Stripe Price objects
 * exist in the Stripe dashboard (test and live modes each need their own).
 */
return [
    'starter' => [
        'name' => 'Starter',
        'stripe_price' => env('STRIPE_PRICE_STARTER', 'price_starter_placeholder'),
        'price_display' => '$29/mo',
    ],

    'growth' => [
        'name' => 'Growth',
        'stripe_price' => env('STRIPE_PRICE_GROWTH', 'price_growth_placeholder'),
        'price_display' => '$79/mo',
    ],

    'pro' => [
        'name' => 'Pro',
        'stripe_price' => env('STRIPE_PRICE_PRO', 'price_pro_placeholder'),
        'price_display' => '$149/mo',
    ],
];
