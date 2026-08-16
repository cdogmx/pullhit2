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

    // ---- Photo-derived condition estimate -------------------------------
    //
    // Centering score = 1000 − penalty × (percentage points off centre on the
    // worse axis). Fitted to TAG cert Y1267951: 53.31/46.69 scores 970, so
    // 30 points of penalty over 3.31 points of deviation. ONE anchor — re-fit
    // this as more certs are collected.
    'centering_penalty_per_point' => (float) env('GRADING_CENTERING_PENALTY', 9.06),

    // 0–1000 condition score => the grade the dossier prices. Per TAG's mapping,
    // a PSA 10 sits roughly in 900–1000 and a 9 in 800–900.
    'score_bands' => [
        '10' => [900, 1000],
        '9' => [800, 900],
        '8' => [700, 800],
    ],

    // Spread on the estimated score, in points. Base is the honest error of the
    // attributes we DID observe; each unobserved attribute widens it further.
    'sigma_base' => (float) env('GRADING_SIGMA_BASE', 25),
    'sigma_per_unseen' => (float) env('GRADING_SIGMA_PER_UNSEEN', 20),

    // What an unobserved attribute costs the estimate. An unseen defect can only
    // ever drag a grade down, so not-looking is never free. Surface is the big
    // one: it needs photometric stereo (multi-angle lighting) that a phone photo
    // cannot provide, and on the Griffey it was the attribute that set the grade.
    'unseen_penalty' => [
        'surface' => 45,
        'corners' => 30,
        'edges' => 25,
        'centering' => 30,
    ],

    // Advantage (grade EV − sell-raw EV) beyond which we call it, in dollars.
    // Inside the band it's a toss-up.
    'call_threshold' => (float) env('GRADING_CALL_THRESHOLD', 5),
];
