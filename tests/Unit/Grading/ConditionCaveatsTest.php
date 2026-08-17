<?php

use App\Support\Grading\ConditionRollup;
use App\Support\Grading\GradeProjector;

/**
 * Surface is never assessed — the tilt-sequence approach did not survive real
 * artwork (see SurfaceAnalyzer's header). So the estimate must always carry the
 * notice, and must never present itself as confident.
 */
function caveatRollup(): ConditionRollup
{
    $config = [
        'score_bands' => ['10' => [900, 1000], '9' => [800, 900], '8' => [700, 800]],
        'sigma_base' => 25.0,
        'sigma_per_unseen' => 20.0,
        'unseen_penalty' => ['surface' => 45, 'corners' => 30, 'edges' => 25, 'centering' => 30],
    ];

    return new ConditionRollup(new GradeProjector($config), $config);
}

test('an estimate without surface always carries the surface notice', function () {
    $estimate = caveatRollup()->roll(['centering' => 970, 'corners' => 964, 'edges' => 1000]);

    expect($estimate->unseen)->toBe(['surface'])
        ->and($estimate->isConfident())->toBeFalse()
        ->and($estimate->caveats())->toHaveKey('surface')
        ->and($estimate->caveats()['surface'])->toContain('not assessed');
});

test('the notice reaches the payload the UI renders', function () {
    $payload = caveatRollup()->roll(['centering' => 970, 'edges' => 1000])->toArray();

    expect($payload['confident'])->toBeFalse()
        ->and($payload['caveats'])->toHaveKeys(['surface', 'corners'])
        ->and($payload['unseen'])->toContain('surface');
});

test('a fully observed estimate carries no caveats', function () {
    $estimate = caveatRollup()->roll([
        'centering' => 970, 'corners' => 964, 'edges' => 1000, 'surface' => 900,
    ]);

    expect($estimate->caveats())->toBe([])
        ->and($estimate->isConfident())->toBeTrue();
});

test('skipping surface still costs the estimate rather than being free', function () {
    $rollup = caveatRollup();

    $withSurface = $rollup->roll(['centering' => 970, 'corners' => 964, 'edges' => 1000, 'surface' => 970]);
    $without = $rollup->roll(['centering' => 970, 'corners' => 964, 'edges' => 1000]);

    // Not looking must never read as good news.
    expect($without->score)->toBeLessThan($withSurface->score)
        ->and($without->sigma)->toBeGreaterThan($withSurface->sigma)
        ->and($without->probs['10'] ?? 0)->toBeLessThan($withSurface->probs['10'] ?? 0);
});
