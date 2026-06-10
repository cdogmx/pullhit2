<?php

return [
    // Max age of observations the engine will consider.
    'lookback_days' => 365,

    // Hampel/MAD outlier rejection: reject |x - median| > k * 1.4826 * MAD.
    'mad_k' => 3.0,

    // Per-venue multiplicative bias priors applied before blending (§7). eBay
    // "Sold For" can run hot vs. actual paid; tcgplayer is the baseline.
    'venue_priors' => [
        'tcgplayer' => 1.00,
        'ebay' => 0.97,
        'whatnot' => 0.99,
        'own_marketplace' => 1.00,
        'other' => 1.00,
    ],

    // Velocity-aware recency window. half_life = clamp(constant / sales_per_day).
    'half_life' => [
        'min_days' => 14,
        'max_days' => 90,
        'constant' => 7.0,
    ],

    // Confidence score knobs.
    'confidence' => [
        'target_n' => 12,            // n at which the sample factor saturates
        'recency_tau_days' => 30,    // exp(-days_since_newest / tau)
        'full_velocity_days' => 14,  // a sale at least this often => no velocity penalty
    ],

    // Real eBay sold-comp ingestion via Oxylabs. Lazy + cost-capped.
    'ebay' => [
        'enabled' => env('EBAY_REFRESH_ENABLED', true),
        'geo' => 'United States',
        'daily_cap' => (int) env('EBAY_DAILY_CAP', 500), // max Oxylabs requests/day
        'max_results' => 60,

        // On a card view, refresh its eBay comps if they're older than this. The
        // detail page shows an "updating" indicator and live-swaps the new values.
        'view_refresh_hours' => (int) env('EBAY_VIEW_REFRESH_HOURS', 12),

        // Accept only prices within [min, max] × the anchor (TCGCSV/median).
        'price_band' => [0.1, 5.0],

        // Reject listings whose title contains any of these (mystery boxes, lots,
        // proxies, codes, repacks, multi-qty, etc.) — they aren't a genuine
        // single-card sale even when they name the card.
        'blocklist' => [
            'mystery', 'chance', 'random', 'grab bag', 'lot of', 'bundle',
            'proxy', 'custom', 'fake', 'orica', 'repack', 'rip ', 'break',
            'ptcgo', 'ptcg live', 'online code', 'code card', 'digital',
            'sticker', 'jumbo', 'oversized', 'choose', 'pick your', 'you pick',
            'you will receive', 'raffle', 'giveaway', 'spin', 'read description',
        ],
    ],
];
