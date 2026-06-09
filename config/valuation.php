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
];
