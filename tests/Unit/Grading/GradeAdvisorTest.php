<?php

use App\Support\Grading\GradeAdvisor;

function advisor(array $overrides = []): GradeAdvisor
{
    return new GradeAdvisor(array_merge([
        'fee' => 25,
        'shipping' => 10,
        'sale_fee_pct' => 0.0, // isolate the grading math from marketplace fees
        'default_probs' => ['10' => 0.2, '9' => 0.45, '8' => 0.25],
        'call_threshold' => 5,
    ], $overrides));
}

test('grading wins when the PSA 10 upside dwarfs the fee', function () {
    // Raw $20, PSA 10 $400. Even a modest 10 chance clears the $35 cost.
    $advice = advisor()->advise(2000, ['10' => 40000, '9' => 8000, '8' => 3000], [
        '10' => 0.3, '9' => 0.4, '8' => 0.2,
    ]);

    // EV grade = .3*400 + .4*80 + .2*30 + .1*20 (other) - 35 = 120+32+6+2-35 = 125
    expect($advice->evGrade)->toBe(12500)
        ->and($advice->evRaw)->toBe(2000)
        ->and($advice->advantage)->toBe(10500)
        ->and($advice->verdict)->toBe('grade');
});

test('selling raw wins when the graded premium is thin', function () {
    // Raw $18, PSA 10 only $25 — the $35 cost swamps any upside.
    $advice = advisor()->advise(1800, ['10' => 2500, '9' => 2000, '8' => 1800]);

    expect($advice->advantage)->toBeLessThan(0)
        ->and($advice->verdict)->toBe('sell');
});

test('break-even P(10) is the fee over the PSA-10 premium', function () {
    // Raw $20, PSA 10 $120, cost $35 (no sale fee). Need (35)/(120-20) = 0.35.
    $advice = advisor()->advise(2000, ['10' => 12000]);

    expect($advice->breakevenP10)->toBe(0.35);
});

test('break-even is null when a PSA 10 is not worth more than raw', function () {
    $advice = advisor()->advise(5000, ['10' => 4000]); // graded worth LESS

    expect($advice->breakevenP10)->toBeNull()
        ->and($advice->verdict)->toBe('sell');
});

test('marketplace fees lower both sides and are reflected in EV', function () {
    $advice = advisor(['sale_fee_pct' => 0.13])
        ->advise(2000, ['10' => 40000, '9' => 8000, '8' => 3000], ['10' => 0.3, '9' => 0.4, '8' => 0.2]);

    // Raw proceeds net 13%: 2000 * 0.87 = 1740.
    expect($advice->evRaw)->toBe(1740);
});

test('an over-allocated probability distribution is scaled to sum <= 1', function () {
    // Probabilities sum to 1.5 — must be normalized, not treated as > certainty.
    $advice = advisor()->advise(2000, ['10' => 40000, '9' => 8000, '8' => 3000], [
        '10' => 0.6, '9' => 0.6, '8' => 0.3,
    ]);

    expect(array_sum($advice->probs))->toBeLessThanOrEqual(1.0 + 1e-9);
});
