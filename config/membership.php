<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monthly scan allowance (cards identified)
    |--------------------------------------------------------------------------
    |
    | Metered by CARDS identified, not photos: a single scan = 1, a 12-card
    | binder photo = 12. The free cap is sized to stay under ~$1/month of Claude
    | vision cost (Sonnet 4.6 ≈ $0.011 per card identified → 75 ≈ $0.83). These
    | are read by App\Support\Membership\Entitlements and enforced in Phase 4b
    | (scanning); Phase 4a only records them.
    |
    */

    'scan_caps' => [
        'free' => (int) env('FREE_SCAN_CAP', 75),
        'premium' => (int) env('PREMIUM_SCAN_CAP', 2000),
    ],

    'tiers' => [
        'free' => ['name' => 'Free'],
        'premium' => ['name' => 'Premium'],
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
