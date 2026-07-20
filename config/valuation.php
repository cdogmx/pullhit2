<?php

return [
    // Max age of observations the engine will consider.
    'lookback_days' => 365,

    // A card is "surging" when its 30-day trend is at least this many percent up
    // (with enough real sales behind it) — drives the surge flag. Capped at
    // surge_max_pct so thin-prior artifacts (a "+6200%" jump) don't flag.
    'surge_pct' => (int) env('VALUATION_SURGE_PCT', 25),
    'surge_max_pct' => (int) env('VALUATION_SURGE_MAX_PCT', 300),

    // Placeholder premium for a generated Lorcana foil vs its non-foil card,
    // used only to seed an estimate — real foil comps replace it on view.
    'lorcana_foil_multiplier' => (float) env('LORCANA_FOIL_MULTIPLIER', 2.0),

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

    // Velocity-aware recency window. half_life = clamp(constant / sales_per_day),
    // floored so we never overfit a thin market.
    'half_life' => [
        // Floor for a normal/thin market: a sale a week keeps a ~2-week memory.
        'min_days' => 14,
        'max_days' => 90,
        'constant' => 7.0,

        // Velocity-aware floor. The 14-day floor is right for steady cards, but
        // wrong for a freshly-released card selling dozens a day whose price is
        // still falling — there, a fortnight-old sale shouldn't anchor "today's"
        // value. So relax the floor toward `hard_min_days` once the window would
        // still hold ~`floor_sample` sales: floor = clamp(floor_sample / velocity,
        // hard_min_days, min_days). Only high-velocity cards are affected; steady
        // ones keep the 14-day floor exactly.
        'hard_min_days' => 1,
        'floor_sample' => 12,
    ],

    // Confidence score knobs.
    'confidence' => [
        'target_n' => 12,            // n at which the sample factor saturates
        'recency_tau_days' => 30,    // exp(-days_since_newest / tau)
        'full_velocity_days' => 14,  // a sale at least this often => no velocity penalty

        // Single-seller dominance penalty — shill/wash resistance on public eBay
        // data (we can see the seller, never the buyer). When one seller is
        // behind most comps, lower confidence: a market of one isn't a market.
        'concentration_min_n' => 3,      // need >= this many seller-tagged comps to judge
        'concentration_threshold' => 0.5, // share above which the penalty kicks in
        'concentration_floor' => 0.6,    // confidence multiplier at 100% single-seller
    ],

    // Daily value/portfolio snapshots (valuation:snapshot). We only snapshot
    // higher-rarity / valued cards (plus anything owned or wishlisted) to avoid
    // tracking the long tail of bulk commons.
    'snapshot' => [
        // Minimum headline median (cents) for a card to be snapshotted on value alone.
        'min_value' => (int) env('SNAPSHOT_MIN_VALUE', 300),

        // Rarities excluded unless the card is owned/wishlisted (reuses the eBay
        // skip notion — bulk commons aren't worth a daily row).
        'skip_rarities' => array_filter(array_map(
            'trim',
            explode(',', (string) env('SNAPSHOT_SKIP_RARITIES', 'Common,Uncommon')),
        )),
    ],

    // PriceCharting completed-sales + long-term history via Oxylabs. Fetched
    // once per card on view (its OWN daily counter, separate from eBay), then
    // only again after the refresh window — its monthly series changes slowly.
    'pricecharting' => [
        'enabled' => env('PRICECHARTING_REFRESH_ENABLED', true),
        // Its OWN Oxylabs daily budget (separate counter from eBay) so a bulk
        // PriceCharting sweep can't starve the interactive eBay on-view refresh.
        // eBay 3500 + PriceCharting 3500 = 7000/day ≈ 210k/month, under the
        // 220k plan ceiling (~7.3k/day) with a small buffer for retries.
        'daily_cap' => (int) env('PRICECHARTING_DAILY_CAP', 3500),

        // Singles are only worth a PriceCharting pull (graded + long-term
        // history) above this value — spending a scrape on a 10¢ common is
        // waste, and there are ~50k of them. Sealed products always pull.
        'singles_min_cents' => (int) env('PRICECHARTING_SINGLES_MIN_CENTS', 500),

        // Low rarities we skip for singles UNLESS the card is from a vintage set
        // (old commons/uncommons can still be valuable). Comma-separated; C/UC
        // are the One Piece / Japanese abbreviations.
        'skip_rarities' => array_filter(array_map(
            'trim',
            explode(',', (string) env('PRICECHARTING_SKIP_RARITIES', 'Common,Uncommon,C,UC')),
        )),

        // A set released before this year counts as "vintage" — its low-rarity
        // singles bypass the skip above.
        'vintage_before_year' => (int) env('PRICECHARTING_VINTAGE_BEFORE_YEAR', 2004),
    ],

    // Real eBay sold-comp ingestion via Oxylabs. Lazy + cost-capped.
    'ebay' => [
        'enabled' => env('EBAY_REFRESH_ENABLED', true),
        'geo' => 'United States',
        'daily_cap' => (int) env('EBAY_DAILY_CAP', 3500), // max Oxylabs requests/day
        'max_results' => 60,

        // Ship-to US postal code (_stpos) — eBay ranks/estimates from a domestic
        // buyer's vantage, matching the geo we scrape from.
        'postal' => env('EBAY_POSTAL', '53094'),

        // eBay/Oxylabs occasionally return a valid results-page shell with NO
        // rendered listing cards (a degraded render / soft anti-bot). Re-fetch up
        // to this many times before giving up; an explicit "no matches" page is
        // trusted immediately. Kept small — each attempt is a paid Oxylabs call.
        'fetch_attempts' => (int) env('EBAY_FETCH_ATTEMPTS', 3),

        // On a card view, refresh its eBay comps if they're older than this. The
        // detail page shows an "updating" indicator and live-swaps the new values.
        'view_refresh_hours' => (int) env('EBAY_VIEW_REFRESH_HOURS', 12),

        // Don't spend Oxylabs calls on low-value rarities — the on-view refresh
        // skips these (admin "Refresh now" + the CLI still work). Comma-separated.
        'skip_rarities' => array_filter(array_map(
            'trim',
            explode(',', (string) env('EBAY_SKIP_RARITIES', 'Common,Uncommon')),
        )),

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

        // Broad sold-listing sweeps: one paid query returns many recent sales
        // that we match back to catalog cards (vs the per-card pull above). The
        // scheduler runs valuation:sweep-ebay; each search only fires when its
        // own interval has elapsed, staggering calls under the daily cap.
        'sweep' => [
            'enabled' => env('EBAY_SWEEP_ENABLED', true),

            // Minimum title→card match score to auto-apply a sale (0..1). Below
            // this the listing is logged to ebay_sweep_misses for tuning, never
            // applied to a value.
            'min_score' => (float) env('EBAY_SWEEP_MIN_SCORE', 0.75),

            // Configured graded searches. `interval_minutes` throttles each one
            // independently. `_dcat=183454` is eBay's "CCG Individual Cards"
            // category (covers every TCG); `_ipg=240` pulls a full page of comps.
            'searches' => [
                [
                    'label' => 'pokemon-psa10',
                    'language' => 'en',
                    'interval_minutes' => 20,
                    'url' => 'https://www.ebay.com/sch/i.html?_nkw=pokemon+psa+10&_sacat=0&LH_Sold=1&LH_Complete=1&Language=English&_dcat=183454&_ipg=240',
                ],
                [
                    'label' => 'onepiece-psa10',
                    'language' => 'en',
                    'interval_minutes' => 60,
                    'url' => 'https://www.ebay.com/sch/i.html?_nkw=one+piece+card+psa+10&_sacat=0&LH_Sold=1&LH_Complete=1&Language=English&_dcat=183454&_ipg=240',
                ],
                [
                    'label' => 'lorcana-psa10',
                    'language' => 'en',
                    'interval_minutes' => 120,
                    'url' => 'https://www.ebay.com/sch/i.html?_nkw=disney+lorcana+psa+10&_sacat=0&LH_Sold=1&LH_Complete=1&Language=English&_dcat=183454&_ipg=240',
                ],
            ],
        ],
    ],
];
