<?php

use App\Support\Grading\CenteringMeasurer;
use App\Support\Grading\ConditionRollup;

/**
 * Real TAG certs, kept as calibration anchors.
 *
 * The centering constant is a fit, and it was fitted to ONE cert. These tests
 * exist so the next person to change it finds out immediately which real cards
 * they just moved, rather than discovering it from a user whose GEM MINT came
 * back predicted as an 8.
 */

/** Cert Y1267951 — the anchor the constant was originally fitted to. */
test('the Griffey anchor still lands on the centering score TAG published', function () {
    $c = (new CenteringMeasurer)->score(
        max(abs(53.31 - 50), abs(48.13 - 50)),
    );

    // TAG reported 970 for that front.
    expect($c)->toBeGreaterThanOrEqual(965)->toBeLessThanOrEqual(975);
});

/**
 * Cert D7145734 — Milotic ex, SSP #237/191, Special Illustration Rare.
 * TAG 10 GEM MINT. Front 46L/54R 47T/53B, back 53L/47R 49T/51B, 0 dings on
 * corners, edges and surface on both sides; sub-scores 990–1000 throughout.
 */
test('the Milotic GEM MINT scores near the top on centering', function () {
    $measurer = new CenteringMeasurer;

    $front = $measurer->score(max(abs(46 - 50), abs(47 - 50)));
    $back = $measurer->score(max(abs(53 - 50), abs(49 - 50)));

    // A 4-point deviation on a card TAG called a 10. If a change to the penalty
    // ever drags this under ~950, it is saying a GEM MINT has mediocre
    // centering, which is a claim worth failing a build over.
    expect($front)->toBe(964)
        ->and($back)->toBe(973)
        ->and(min($front, $back))->toBeGreaterThan(950);
});

test('a GEM MINT still comes back uncertain when we only measured centering', function () {
    // The honest consequence of seeing one attribute out of four, on a card we
    // KNOW is a 10. Corners, edges and surface have no detector, so the rollup
    // penalises each and widens the spread. It should not confidently call this
    // a 10 — it has not looked at the three things that could stop it being one.
    $estimate = app(ConditionRollup::class)->roll(['centering' => 964]);

    expect($estimate->unseen)->toContain('corners')
        ->and($estimate->unseen)->toContain('edges')
        ->and($estimate->unseen)->toContain('surface')
        // Pessimism is the point: unseen drags the score down, never up.
        ->and($estimate->score)->toBeLessThan(964)
        ->and($estimate->isConfident())->toBeFalse();
});

test('what TAG saw and we cannot is exactly what the caveats say', function () {
    $estimate = app(ConditionRollup::class)->roll(['centering' => 964]);

    // TAG measured 0 dings on corners, edges and surface for this card. We
    // measured none of those, and the caveat for surface says so in the
    // strongest terms because it is the one most likely to hold a card back.
    expect($estimate->caveats())->toHaveKey('surface')
        ->and($estimate->caveats()['surface'])->toContain('not assessed');
});

test('the live readout uses the same centering line the server does', function () {
    // The bench shows a score while a guide is dragged, which means the formula
    // exists twice. Retuning the constant in config without the front end would
    // leave the number moving under the cursor disagreeing with the number in
    // the saved run — the exact confusion this bench exists to remove.
    $tsx = file_get_contents(resource_path('js/components/grading/side-capture.tsx'));

    preg_match('/1000 -\s*([0-9.]+)\s*\*/', $tsx, $m);

    expect($m[1] ?? null)->not->toBeNull()
        ->and((float) $m[1])->toBe((float) config('grading.centering_penalty_per_point'));
});
