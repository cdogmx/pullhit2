<?php

return [

    // Max candidate catalog matches returned per identified card.
    'max_candidates' => (int) env('SCAN_MAX_CANDIDATES', 5),

    // Safety cap on how many cards a single bulk photo will process.
    'bulk_max_cards' => (int) env('SCAN_BULK_MAX_CARDS', 20),

    // Margin added around each detected bulk card before cropping, as a fraction
    // of the card's own size. Vision boxes run tight/imprecise, so a little
    // padding stops borders being clipped. Clamped to the image bounds.
    'bulk_crop_padding' => (float) env('SCAN_BULK_CROP_PADDING', 0.08),

    // Longest image edge accepted server-side (the client also downscales).
    'max_image_px' => (int) env('SCAN_MAX_IMAGE_PX', 1568),

    // ---- Cards we do not hold -------------------------------------------
    //
    // A scan that matches nothing is a dead end: the card is real, somebody is
    // holding it, and they cannot log it. When enabled, such a scan creates the
    // row PROVISIONALLY — usable by its finder, hidden from browse, search,
    // pricing and the sitemap until a human confirms it.
    //
    // Two thresholds decide when that happens, and both have to be met. A scan
    // is only "unmatched" when the best catalog match is BELOW match_floor, and
    // the read itself is only trusted enough to build a row from at or above
    // read_floor. Between those, the scan shows its candidates and creates
    // nothing — a confident read that nearly matched something is far more
    // likely to be a card we hold under a slightly different name than a card
    // we do not hold at all.
    'provisional' => [
        'enabled' => (bool) env('SCAN_PROVISIONAL_ENABLED', true),

        // Best catalog match must be worse than this to count as unmatched.
        // Deliberately low: creating a near-duplicate of a card we already hold
        // is the expensive mistake, and a wrong guess here makes one.
        'match_floor' => (float) env('SCAN_PROVISIONAL_MATCH_FLOOR', 0.45),

        // The vision read must be at least this confident. A hesitant read makes
        // a row with a wrong name, and the name feeds the identity hash — so the
        // official import will never match it and the duplicate is permanent.
        'read_floor' => (float) env('SCAN_PROVISIONAL_READ_FLOOR', 0.75),

        // Rows one person may create per day. A scanner pointed at something that
        // is not a card at all should cost us a handful of rows, not a catalog.
        'daily_per_user' => (int) env('SCAN_PROVISIONAL_DAILY_PER_USER', 25),
    ],

    // Max Hamming distance (0–64) between two card dHashes to treat them as the
    // same card and recognise it from the cache without an AI read. Lower = more
    // conservative (fewer false recognitions, more AI calls).
    'fingerprint_max_distance' => (int) env('SCAN_FINGERPRINT_MAX_DISTANCE', 10),

];
