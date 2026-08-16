<?php

use App\Support\Grading\CenteringMeasurer;
use App\Support\Grading\ConditionRollup;
use App\Support\Grading\GradeProjector;
use App\Support\Grading\Rect;

/**
 * The reference card throughout is TAG cert Y1267951 — a 1989 Upper Deck #1 Ken
 * Griffey Jr that TAG scored 879 (8.5 NM MT+). Its published report is the only
 * ground truth we have, so the maths is pinned to it.
 *
 * Config is injected rather than read from the container (same pattern as
 * GradeAdvisorTest) so this stays a real unit test: pure arithmetic, no app boot.
 */
function gradingConfig(array $overrides = []): array
{
    return array_merge([
        'centering_penalty_per_point' => 9.06,
        'score_bands' => ['10' => [900, 1000], '9' => [800, 900], '8' => [700, 800]],
        'sigma_base' => 25.0,
        'sigma_per_unseen' => 20.0,
        'unseen_penalty' => ['surface' => 45, 'corners' => 30, 'edges' => 25, 'centering' => 30],
    ], $overrides);
}

function rollup(array $overrides = []): ConditionRollup
{
    $config = gradingConfig($overrides);

    return new ConditionRollup(new GradeProjector($config), $config);
}

test('centering matches the ratios TAG published for the reference card', function () {
    // TAG reports 53.31 / 46.69 left-right and 48.13 / 51.87 top-bottom. Build a
    // card + frame whose margins reproduce exactly those splits.
    $card = new Rect(0.0, 0.0, 1.0, 1.0);
    $frame = new Rect(
        x: 0.5331 * 0.2,          // left margin takes 53.31% of 0.2 total h-margin
        y: 0.4813 * 0.2,
        width: 1.0 - 0.2,
        height: 1.0 - 0.2,
    );

    $c = (new CenteringMeasurer(9.06))->measure($card, $frame);

    expect(round($c->left, 2))->toBe(53.31)
        ->and(round($c->right, 2))->toBe(46.69)
        ->and(round($c->top, 2))->toBe(48.13)
        ->and(round($c->bottom, 2))->toBe(51.87)
        ->and($c->worstAxis())->toBe('left-right')
        ->and($c->worstDeviation())->toBe(3.31);
});

test('the centering score reproduces TAG\'s 970 for the reference card', function () {
    $score = (new CenteringMeasurer(9.06))->score(3.31);

    // TAG's front centering is 970; the fit is anchored there.
    expect($score)->toBeGreaterThanOrEqual(968)->toBeLessThanOrEqual(972);
});

test('the centering line lands near PSA tolerances away from its anchor', function () {
    $m = new CenteringMeasurer(9.06);

    // 60/40 is PSA's stated 9 tolerance; 65/35 their 8.
    expect($m->score(10))->toBeGreaterThanOrEqual(890)->toBeLessThanOrEqual(925)
        ->and($m->score(15))->toBeGreaterThanOrEqual(845)->toBeLessThanOrEqual(880);
});

test('a perfectly centred card scores 1000 and a wildly off one bottoms out', function () {
    $m = new CenteringMeasurer(9.06);

    expect($m->score(0))->toBe(1000)
        ->and($m->score(50))->toBeLessThan(600);
});

test('centering refuses a frame that escapes the card rather than inventing a ratio', function () {
    $m = new CenteringMeasurer(9.06);
    $card = new Rect(0.0, 0.0, 1.0, 1.0);

    expect(fn () => $m->measure($card, new Rect(-0.1, 0.1, 0.8, 0.8)))
        ->toThrow(InvalidArgumentException::class);
});

test('the roll-up takes the weakest link, not the average', function () {
    // TAG's front sub-scores for the reference card, and the total they report.
    $estimate = rollup()->roll([
        'centering' => 970,
        'corners' => 964,
        'surface' => 867,
        'edges' => 1000,
    ]);

    // The mean would be 950; TAG reports 876 against a minimum of 867.
    expect($estimate->score)->toBe(867)
        ->and($estimate->limitingAttribute())->toBe('surface')
        ->and($estimate->isConfident())->toBeTrue();
});

test('an unseen attribute drags the estimate down and widens it', function () {
    $rollup = rollup();

    $seen = $rollup->roll(['centering' => 970, 'corners' => 964, 'edges' => 1000, 'surface' => 950]);
    $blind = $rollup->roll(['centering' => 970, 'corners' => 964, 'edges' => 1000]);

    expect($blind->unseen)->toBe(['surface'])
        ->and($blind->score)->toBeLessThan($seen->score)
        ->and($blind->sigma)->toBeGreaterThan($seen->sigma)
        ->and($blind->isConfident())->toBeFalse();
});

test('no observed attributes yields no opinion rather than a midpoint guess', function () {
    $estimate = rollup()->roll([]);

    expect($estimate->probs)->toBe([])
        ->and($estimate->limitingAttribute())->toBeNull();
});

test('the projector returns a spread of grades, never a single certainty', function () {
    $probs = (new GradeProjector(gradingConfig()))->project(879, 25);

    expect(array_sum($probs))->toBeGreaterThan(0.5)
        ->and(count($probs))->toBeGreaterThan(1);

    // 879 sits in the 9 band, so 9 should lead, and nothing should be a lock.
    expect($probs['9'])->toBeGreaterThan($probs['10'] ?? 0)
        ->and(max($probs))->toBeLessThan(0.95);
});

test('more uncertainty flattens the distribution', function () {
    $projector = new GradeProjector(gradingConfig());

    $tight = $projector->project(950, 15);
    $loose = $projector->project(950, 80);

    expect($tight['10'])->toBeGreaterThan($loose['10']);
});

test('the projected distribution plugs straight into the advisor', function () {
    $estimate = rollup()->roll(['centering' => 970, 'corners' => 964, 'edges' => 1000]);

    $advice = (new App\Support\Grading\GradeAdvisor([
        'fee' => 25, 'shipping' => 10, 'sale_fee_pct' => 0.13,
        'default_probs' => ['10' => 0.2, '9' => 0.45, '8' => 0.25],
        'call_threshold' => 5,
    ]))->advise(
        24250,                                   // raw, in cents
        ['10' => 380000, '9' => 35405, '8' => 20000],
        $estimate->probs,
    );

    expect($advice->toArray())->toBeArray();
});
