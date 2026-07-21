<?php

return [
    // Default costs of getting a card graded (PSA bulk-ish), in dollars. The
    // advisor and Sensei reason from these; the user can override in conversation.
    'fee' => (float) env('GRADING_FEE', 25),
    'shipping' => (float) env('GRADING_SHIPPING', 10),

    // Marketplace fee taken out of a sale (eBay ~13%), applied to BOTH the graded
    // and the raw sale so it doesn't distort the comparison — it just lowers both.
    'sale_fee_pct' => (float) env('GRADING_SALE_FEE_PCT', 0.13),

    // When we have no real comp for an intermediate grade, model it as a fraction
    // of the PSA 10 value (rough, flagged as an estimate in the dossier).
    'modeled_grade_multiplier' => [
        '9' => 0.55,
        '8' => 0.32,
    ],

    // A neutral prior grade distribution used when the user hasn't described the
    // card's condition yet. Deliberately modest — Sensei refines it from what the
    // user says about centering / corners / edges / surface. Remainder = "other"
    // (a low grade you'd have been better off selling raw).
    'default_probs' => [
        '10' => 0.20,
        '9' => 0.45,
        '8' => 0.25,
    ],

    // Advantage (grade EV − sell-raw EV) beyond which we call it, in dollars.
    // Inside the band it's a toss-up.
    'call_threshold' => (float) env('GRADING_CALL_THRESHOLD', 5),
];
