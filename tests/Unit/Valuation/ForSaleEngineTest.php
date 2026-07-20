<?php

use App\Support\Valuation\CombinedValue;
use App\Support\Valuation\ForSaleEngine;

function forSaleEngine(): ForSaleEngine
{
    return new ForSaleEngine([
        'floor_frac' => 0.5,
        'ceil_frac' => 3.0,
        'low_percentile' => 0.15,
        'min_asks' => 2,
    ]);
}

test('it returns the lowest realistic ask, near the bottom of the kept asks', function () {
    // Real asks cluster 305-350; the engine should land near the low end.
    $asks = [30500, 31000, 31900, 33000, 34900, 40000];

    $result = forSaleEngine()->value($asks);

    expect($result['n'])->toBe(6)
        ->and($result['for_sale'])->toBeGreaterThanOrEqual(30500)
        ->and($result['for_sale'])->toBeLessThan(32000); // low percentile, not the median
});

test('it drops a lowball and a moonshot before taking the low ask', function () {
    // 500 is a mispriced "lot"; 999900 is a moonshot. Median ~31900 -> keep 30-34k.
    $asks = [500, 30500, 31000, 31900, 33000, 34900, 999900];

    $result = forSaleEngine()->value($asks);

    // Junk removed: n counts only the kept asks, and the floor isn't the $5 lot.
    expect($result['n'])->toBe(5)
        ->and($result['for_sale'])->toBeGreaterThan(30000);
});

test('it needs at least the minimum number of plausible asks', function () {
    expect(forSaleEngine()->value([31000]))->toBeNull()          // one ask
        ->and(forSaleEngine()->value([]))->toBeNull()            // none
        ->and(forSaleEngine()->value([31000, 0, -5]))->toBeNull(); // one valid after cleaning
});

test('combined leans toward asks when they undercut the sold price', function () {
    $cfg = ['combine_up_weight' => 0.15, 'combine_down_weight' => 0.5];

    // Asks below sold -> softening -> pulled halfway down.
    expect(CombinedValue::blend(32000, 30000, $cfg))->toBe(31000);
    // Asks above sold -> barely lifts (asking high is cheap).
    expect(CombinedValue::blend(32000, 40000, $cfg))->toBe(33200);
});

test('combined falls back to whichever single input exists', function () {
    $cfg = ['combine_up_weight' => 0.15, 'combine_down_weight' => 0.5];

    expect(CombinedValue::blend(32000, null, $cfg))->toBe(32000)
        ->and(CombinedValue::blend(null, 30000, $cfg))->toBe(30000)
        ->and(CombinedValue::blend(null, null, $cfg))->toBeNull();
});
