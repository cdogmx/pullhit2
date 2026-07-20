<?php

use App\Support\Valuation\Observation;
use App\Support\Valuation\ValuationEngine;
use Carbon\CarbonImmutable;

/** Engine config with a pronounced eBay prior so its effect is testable. */
function engine(): ValuationEngine
{
    return new ValuationEngine([
        'lookback_days' => 365,
        'mad_k' => 3.0,
        'venue_priors' => ['tcgplayer' => 1.0, 'ebay' => 0.90, 'whatnot' => 1.0, 'own_marketplace' => 1.0, 'other' => 1.0],
        'half_life' => ['min_days' => 14, 'max_days' => 90, 'constant' => 7.0],
        'confidence' => [
            'target_n' => 12,
            'recency_tau_days' => 30,
            'full_velocity_days' => 14,
            'concentration_min_n' => 3,
            'concentration_threshold' => 0.5,
            'concentration_floor' => 0.6,
        ],
    ]);
}

/** An observation tagged with a seller, for concentration tests. */
function obsBy(string $seller, int $price, int $daysAgo, int $key): Observation
{
    return new Observation($price, 'tcgplayer', CarbonImmutable::now()->subDays($daysAgo), $key, $seller);
}

function obs(int $price, string $venue = 'tcgplayer', int $daysAgo = 10, int $key = 0): Observation
{
    return new Observation($price, $venue, CarbonImmutable::now()->subDays($daysAgo), $key ?: $price + $daysAgo);
}

test('returns null when there are no observations', function () {
    expect(engine()->value([]))->toBeNull();
});

test('MAD rejects a planted outlier instead of letting it inflate the median', function () {
    $observations = [
        obs(1000, key: 1), obs(1010, key: 2), obs(990, key: 3),
        obs(1005, key: 4), obs(995, key: 5),
        obs(9000, key: 99), // wild ask recorded as sold
    ];

    $result = engine()->value($observations);

    expect($result->outlierKeys)->toContain(99)
        ->and($result->nSales)->toBe(5)
        ->and($result->median)->toBeLessThan(1100); // not dragged toward the 9000
});

test('venue bias priors normalize prices before blending', function () {
    $result = engine()->value([
        obs(1000, 'ebay', 5, 1), obs(1000, 'ebay', 6, 2),
        obs(1000, 'ebay', 7, 3), obs(1000, 'ebay', 8, 4),
    ]);

    // 1000 x 0.90 prior => ~900
    expect($result->median)->toBeGreaterThan(880)->toBeLessThan(920);
});

test('recent sales are weighted more heavily than old ones (EWMA)', function () {
    $result = engine()->value([
        obs(1000, 'tcgplayer', 78, 1), obs(1000, 'tcgplayer', 74, 2), obs(1000, 'tcgplayer', 70, 3),
        obs(1200, 'tcgplayer', 1, 4), obs(1200, 'tcgplayer', 2, 5), obs(1200, 'tcgplayer', 3, 6),
    ]);

    // Plain median would be 1100; recency weighting pulls it toward the recent 1200s.
    expect($result->median)->toBeGreaterThan(1150);
});

test('a thin, stale, dispersed market yields lower confidence than a thick recent one', function () {
    $thin = engine()->value([
        obs(800, 'tcgplayer', 200, 1), obs(1500, 'tcgplayer', 150, 2), obs(1100, 'tcgplayer', 120, 3),
    ]);

    $thick = collect(range(1, 14))
        ->map(fn ($i) => obs(1000 + $i * 5, 'tcgplayer', $i, $i))
        ->all();
    $thickResult = engine()->value($thick);

    expect($thin->confidence)->toBeLessThan(0.4)
        ->and($thickResult->confidence)->toBeGreaterThan($thin->confidence);
});

test('single-seller dominance lowers confidence vs the same comps from many sellers', function () {
    // Eight tight, recent sales — strong on every axis except who is selling.
    $prices = [1000, 1010, 990, 1005, 995, 1000, 1008, 992];

    $oneSeller = [];
    $manySellers = [];
    foreach ($prices as $i => $p) {
        $oneSeller[] = obsBy('pump_account', $p, $i + 1, $i + 1);
        $manySellers[] = obsBy('seller'.$i, $p, $i + 1, $i + 1);
    }

    $dominated = engine()->value($oneSeller);
    $diverse = engine()->value($manySellers);

    expect($dominated->topSellerShare)->toBe(1.0)
        ->and($diverse->topSellerShare)->toBeLessThan(0.3)
        ->and($dominated->confidence)->toBeLessThan($diverse->confidence);
});

test('seller concentration is not judged below the minimum sample', function () {
    // Two seller-tagged comps — below concentration_min_n, so no share, no penalty.
    $result = engine()->value([
        obsBy('solo', 1000, 1, 1),
        obsBy('solo', 1010, 2, 2),
    ]);

    expect($result->topSellerShare)->toBeNull();
});

test('it produces an ordered distribution', function () {
    $result = engine()->value(
        collect(range(1, 12))->map(fn ($i) => obs(900 + $i * 20, 'tcgplayer', $i, $i))->all(),
    );

    expect($result->low)->toBeLessThanOrEqual($result->p25)
        ->and($result->p25)->toBeLessThanOrEqual($result->median)
        ->and($result->median)->toBeLessThanOrEqual($result->p75)
        ->and($result->p75)->toBeLessThanOrEqual($result->high)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.0)
        ->and($result->confidence)->toBeLessThanOrEqual(1.0);
});

/** Engine with the velocity-aware floor enabled (as production config has it). */
function fastEngine(): ValuationEngine
{
    return new ValuationEngine([
        'lookback_days' => 365,
        'mad_k' => 3.0,
        'venue_priors' => ['tcgplayer' => 1.0, 'ebay' => 1.0, 'whatnot' => 1.0, 'own_marketplace' => 1.0, 'other' => 1.0],
        'half_life' => ['min_days' => 14, 'max_days' => 90, 'constant' => 7.0, 'hard_min_days' => 1, 'floor_sample' => 12],
        'confidence' => [
            'target_n' => 12, 'recency_tau_days' => 30, 'full_velocity_days' => 14,
            'concentration_min_n' => 3, 'concentration_threshold' => 0.5, 'concentration_floor' => 0.6,
        ],
    ]);
}

test('the velocity floor leaves a steady, low-velocity market unchanged', function () {
    // ~1 sale a week over three months. The velocity-aware floor must not alter a
    // slow market at all — same half-life and median as the plain 14-day-floor engine.
    $obs = collect(range(0, 12))->map(fn ($i) => obs(1000, 'tcgplayer', $i * 7, $i))->all();

    $plain = engine()->value($obs);
    $fast = fastEngine()->value($obs);

    expect($fast->halfLifeDays)->toBe($plain->halfLifeDays)
        ->and($fast->median)->toBe($plain->median);
});

test('the half-life floor relaxes for a high-velocity market so recent sales lead', function () {
    // A freshly-released card: ~20 sales/day for 10 days, price FALLING 500 -> 300
    // (newest = today = lowest, oldest = 10 days ago = highest).
    $obs = [];
    $key = 0;
    for ($day = 0; $day <= 10; $day++) {
        $price = 30000 + $day * 2000; // today 300, ten days ago 500
        for ($i = 0; $i < 20; $i++) {
            $obs[] = obs($price, 'tcgplayer', $day, ++$key);
        }
    }

    $steady = engine()->value($obs);   // 14-day floor
    $fast = fastEngine()->value($obs); // velocity-aware floor

    // The fast engine uses a much shorter half-life and lands nearer the latest
    // (lower) prices than the steady one anchored by the release-day volume.
    expect($fast->halfLifeDays)->toBeLessThan($steady->halfLifeDays)
        ->and($fast->halfLifeDays)->toBeLessThanOrEqual(2)
        ->and($fast->median)->toBeLessThan($steady->median);
});

test('it reports 24h, 7d, 30d and 90d trends independently', function () {
    // Prior week centered ~1000, recent week centered ~800 -> ~-20% on 7d.
    $obs = [
        obs(1000, 'tcgplayer', 13, 1), obs(1000, 'tcgplayer', 12, 2), obs(1000, 'tcgplayer', 10, 3),
        obs(800, 'tcgplayer', 5, 4), obs(800, 'tcgplayer', 3, 5), obs(800, 'tcgplayer', 1, 6),
    ];

    $result = engine()->value($obs);

    expect($result->trend7d)->not->toBeNull()
        ->and($result->trend7d)->toBeLessThan(-10.0)   // clearly down
        ->and($result->trend30d)->toBeNull()           // no prior 30d window exists
        ->and($result->trend90d)->toBeNull();
});

test('a young market with under two 7-day windows has a null 7d trend', function () {
    // Everything within the last 6 days — no prior 7-day window to compare.
    $obs = collect(range(0, 5))->map(fn ($i) => obs(1000, 'tcgplayer', $i, $i))->all();

    expect(engine()->value($obs)->trend7d)->toBeNull();
});
