<?php

return [

    // Max candidate catalog matches returned per identified card.
    'max_candidates' => (int) env('SCAN_MAX_CANDIDATES', 5),

    // Safety cap on how many cards a single bulk photo will process.
    'bulk_max_cards' => (int) env('SCAN_BULK_MAX_CARDS', 20),

    // Longest image edge accepted server-side (the client also downscales).
    'max_image_px' => (int) env('SCAN_MAX_IMAGE_PX', 1568),

    // Max Hamming distance (0–64) between two card dHashes to treat them as the
    // same card and recognise it from the cache without an AI read. Lower = more
    // conservative (fewer false recognitions, more AI calls).
    'fingerprint_max_distance' => (int) env('SCAN_FINGERPRINT_MAX_DISTANCE', 10),

];
