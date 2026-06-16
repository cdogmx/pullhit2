<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monthly scan-credit allowance (per tier)
    |--------------------------------------------------------------------------
    |
    | One credit = one CARD identified by the AI (a 12-card binder photo = 12).
    | Cache-recognized scans cost NOTHING — they don't call the AI, so they don't
    | consume a credit. The allowance resets monthly (no rollover); overflow is
    | covered by purchased credit packs below. Sized so typical use stays well
    | under the subscription price (~$0.011 Claude vision cost per AI read).
    |
    */

    'scan_caps' => [
        'free' => (int) env('FREE_SCAN_CAP', 50),
        'collector' => (int) env('COLLECTOR_SCAN_CAP', 600),
        'guru' => (int) env('GURU_SCAN_CAP', 3000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-tier list limits (-1 = unlimited)
    |--------------------------------------------------------------------------
    |
    | How many named collections / wishlists / target-price alerts a tier allows.
    | Near-zero marginal cost, so paid tiers can be generous.
    |
    */

    'limits' => [
        'collections' => ['free' => 1, 'collector' => 5, 'guru' => -1],
        'wishlists' => ['free' => 1, 'collector' => 5, 'guru' => -1],
        'alerts' => ['free' => 1, 'collector' => 50, 'guru' => -1],
    ],

    'tiers' => [
        'free' => ['name' => 'Free', 'price_label' => 'Free'],
        'collector' => ['name' => 'Collector', 'price_label' => env('COLLECTOR_PRICE_LABEL', '$4.99/mo')],
        'guru' => ['name' => 'Guru', 'price_label' => env('GURU_PRICE_LABEL', '$19.99/mo')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Purchasable scan-credit packs (one-time)
    |--------------------------------------------------------------------------
    |
    | Top-ups when the monthly allowance runs out. Purchased credits persist and
    | are spent only after the monthly allowance. Every pack is priced ≥2× the
    | per-scan cost, so we make money on every credit sold. `dodo_product`
    | resolves to a Dodo one-time product id (set in Phase C billing).
    |
    */

    'credit_packs' => [
        'credit100' => ['credits' => 100, 'price_label' => '$3.99', 'dodo_product' => env('DODO_PRODUCT_ID_CREDIT100')],
        'credit500' => ['credits' => 500, 'price_label' => '$14.99', 'dodo_product' => env('DODO_PRODUCT_ID_CREDIT500')],
        'credit2000' => ['credits' => 2000, 'price_label' => '$44.99', 'dodo_product' => env('DODO_PRODUCT_ID_CREDIT2000')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin emails
    |--------------------------------------------------------------------------
    |
    | Users with these emails are auto-promoted to admin on sign-up (and can be
    | promoted after the fact with `php artisan users:make-admin`). Admins bypass
    | every entitlement gate and have an unlimited scan quota.
    |
    */

    'admins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_EMAILS', 'clint.r.chaney@gmail.com')),
    ))),

];
