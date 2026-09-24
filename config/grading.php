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

    // ---- Submission tiers -----------------------------------------------
    //
    // A tier is not really a choice. Every company caps the declared value
    // each service level accepts, so a card worth more than the cheap tier
    // allows must go up a level. That decides most of the answer on a cheap
    // card: the flat 'fee' above is a guess, and a guess that is low says yes
    // to submissions that lose money.
    //
    // 'max_insured_value' is PSA's own column name, in dollars, measured
    // against the RAW card being sent rather than its hoped-for graded value.
    // Null means no cap — the top of the range. 'fee' is per card, excluding
    // shipping, which stays separate above because it is per submission.
    //
    // These change several times a year and differ by country. When they move,
    // update them here and say so in 'source' — a stale figure becomes a
    // recommendation to send a card that should have stayed raw. costFor()
    // falls back to the flat 'fee' above for any company with no tiers.
    'submission_tiers' => [
        'psa' => [
            'name' => 'PSA',
            'source' => 'PSA Grading Services price list (United States)',
            'tiers' => [
                ['name' => 'Standard', 'fee' => 59.99, 'max_insured_value' => 1000, 'turnaround' => '90–100 business days'],
                ['name' => 'Priority', 'fee' => 79.99, 'max_insured_value' => 1500, 'turnaround' => '70–80 business days'],
                ['name' => 'Express', 'fee' => 199, 'max_insured_value' => 2500, 'turnaround' => '20–30 business days'],
                ['name' => 'Super Express', 'fee' => 349, 'max_insured_value' => 5000, 'turnaround' => '10–15 business days'],
                ['name' => 'Premier', 'fee' => 599, 'max_insured_value' => 10000, 'turnaround' => '7–10 business days'],
                // Premium is "$999+" against "$25,000+" — open at both ends, so
                // it takes anything above Premier and quotes the floor. A card
                // over $25,000 costs more than this says, which is the right
                // way round for a number the advisor spends against.
                ['name' => 'Premium', 'fee' => 999, 'max_insured_value' => null, 'turnaround' => '5–7 business days'],

                // Value and Value Bulk are listed but shown as unavailable, so
                // they are not here. A tier nobody can book is not a price.
            ],
        ],
    ],

    // ---- Published centering tolerances ---------------------------------
    //
    // Centering is the one attribute graders put numbers on, and the numbers
    // differ between them — so the same card can be a 10 on centering to one
    // company and a 9 to another. Worth saying out loud: it decides where a
    // card is worth sending.
    //
    // Each limit is the largest share the WIDER side of an axis may take. A
    // front limit of 55 means 55/45 or better on both axes. Front and back get
    // their own limits because every standard is far more forgiving of the
    // back, and grades are listed best-first — the first one a card satisfies
    // is the answer.
    //
    // ONLY PSA IS FILLED IN. Its tolerances are published plainly and are the
    // ones I can state without guessing. The others are left empty on purpose:
    // a tolerance invented from memory is a number somebody submits a card on,
    // and being wrong there costs real money. Fill each from that company's own
    // published standard and it appears in the bench and on shared reports
    // automatically.
    'centering_standards' => [
        'psa' => [
            'name' => 'PSA',
            'source' => 'PSA published grading standards',
            'grades' => [
                '10' => ['front' => 55, 'back' => 75, 'label' => 'Gem Mint'],
                '9' => ['front' => 60, 'back' => 90, 'label' => 'Mint'],
                '8' => ['front' => 65, 'back' => 90, 'label' => 'NM-MT'],
                '7' => ['front' => 70, 'back' => 90, 'label' => 'Near Mint'],
            ],
        ],

        // 'bgs' => ['name' => 'BGS', 'source' => '', 'grades' => [...]],
        // 'cgc' => ['name' => 'CGC', 'source' => '', 'grades' => [...]],
        // 'sgc' => ['name' => 'SGC', 'source' => '', 'grades' => [...]],
        // 'tag' => ['name' => 'TAG', 'source' => '', 'grades' => [...]],
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
