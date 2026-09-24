<?php

use App\Support\Grading\Centering;
use App\Support\Grading\CenteringMeasurer;
use App\Support\Grading\CenteringStandards;

/** A side with a given left/right split and dead-centre top/bottom. */
function split(float $left, float $top = 50.0): Centering
{
    $measurer = new CenteringMeasurer;

    return new Centering(
        left: $left,
        right: 100 - $left,
        top: $top,
        bottom: 100 - $top,
        score: $measurer->score(max(abs($left - 50), abs($top - 50))),
    );
}

test('PSA allows far more on the back than the front', function () {
    // The reason front and back cannot be collapsed into one figure: 65/35 is
    // comfortably inside PSA's back tolerance and nowhere near its front one.
    $tight = split(65.0);
    $perfect = split(50.0);

    $backIsBad = CenteringStandards::assess($perfect, $tight);
    $frontIsBad = CenteringStandards::assess($tight, $perfect);

    expect($backIsBad[0]['grade'])->toBe('10')
        ->and($frontIsBad[0]['grade'])->toBe('8');
});

test('a card on the exact published limit passes rather than failing', function () {
    // PSA's 10 is "55/45 or better". Exactly 55/45 is better-or-equal, and a
    // floating-point comparison must not decide otherwise.
    $report = CenteringStandards::assess(split(55.0), split(75.0));

    expect($report[0]['grade'])->toBe('10');
});

test('the Milotic front lands where TAG put it', function () {
    // Cert D7145734: 46/54 front, 53/47 back, graded TAG 10. Whatever else it
    // was, its centering was not what held it back — and PSA's tolerance says
    // the same.
    $report = CenteringStandards::assess(split(46.0, 47.0), split(53.0, 49.0));

    expect($report[0]['company'])->toBe('PSA')
        ->and($report[0]['grade'])->toBe('10');
});

test('a card outside every listed tolerance says so instead of guessing', function () {
    $report = CenteringStandards::assess(split(80.0), split(50.0));

    expect($report[0]['grade'])->toBeNull()
        ->and($report[0]['label'])->toContain('below the listed tolerances');
});

test('it names the side with the least room, which is the one to re-measure', function () {
    // The front is nearly out against a 55 limit; the back is comfortable
    // against 75. Raw deviation would pick the back and send somebody to
    // re-measure the wrong side.
    $report = CenteringStandards::assess(split(54.0), split(60.0));

    expect($report[0]['limited_by'])->toBe('front');
});

test('one side measured is judged on that side alone, not failed for the other', function () {
    $report = CenteringStandards::assess(split(52.0), null);

    expect($report[0]['grade'])->toBe('10')
        ->and($report[0]['judged'])->toBe(['front']);
});

test('nothing measured is no verdict at all', function () {
    expect(CenteringStandards::assess(null, null))->toBe([]);
});

test('only companies with real published numbers are listed', function () {
    // Empty entries stay empty on purpose: a tolerance invented from memory is
    // a number somebody submits a card on.
    $report = CenteringStandards::assess(split(50.0), split(50.0));

    expect($report)->not->toBeEmpty();

    foreach ($report as $company) {
        expect($company['source'])->not->toBeNull()
            ->and($company['source'])->not->toBe('');
    }
});
