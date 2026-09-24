<?php

use App\Support\Grading\CenteringStandards;

/**
 * Checks on whatever has been typed into config/grading.php.
 *
 * These tolerances are copied in by hand from each company's published
 * standard, and the ways to get that subtly wrong are silent: a grade list out
 * of order returns the wrong verdict without erroring, and front and back
 * swapped looks entirely plausible while failing every well-centred card.
 * Better a failing test than a number somebody submits a card on.
 */
test('every listed company says where its numbers came from', function () {
    foreach (CenteringStandards::all() as $company) {
        expect($company['name'] ?? null)->toBeString()->not->toBe('')
            // A tolerance with no source is one nobody can check, which is how
            // a remembered number becomes a published-looking one.
            ->and($company['source'] ?? null)->toBeString()->not->toBe('')
            ->and($company['grades'] ?? [])->toBeArray()->not->toBeEmpty();
    }
});

test('each grade gives a front and a back limit, both above dead centre', function () {
    foreach (CenteringStandards::all() as $company) {
        foreach ($company['grades'] as $grade => $limits) {
            $where = "{$company['name']} {$grade}";

            foreach (['front', 'back'] as $side) {
                expect($limits[$side] ?? null)
                    ->toBeNumeric("{$where} is missing a {$side} limit")
                    // Written as the larger share, so 55 means 55/45. Anything
                    // at or below 50 is not a tolerance, it is a mistake.
                    ->toBeGreaterThan(50, "{$where} {$side} limit must exceed 50")
                    ->toBeLessThanOrEqual(100, "{$where} {$side} limit cannot exceed 100");
            }
        }
    }
});

test('the back is never stricter than the front', function () {
    // Every published standard is more forgiving of the back — it is the face
    // that gets looked at. A company where the back were tighter would be
    // remarkable; far likelier is that the two got swapped on the way in, which
    // would fail well-centred cards and pass badly centred ones.
    foreach (CenteringStandards::all() as $company) {
        foreach ($company['grades'] as $grade => $limits) {
            expect((float) $limits['back'])->toBeGreaterThanOrEqual(
                (float) $limits['front'],
                "{$company['name']} {$grade}: the back limit is tighter than the front — are they the right way round?",
            );
        }
    }
});

test('grades are listed best first, and loosen as they go', function () {
    // The first grade a card satisfies is the answer, so the order IS the
    // logic. A list that started at 8 would call every card an 8.
    foreach (CenteringStandards::all() as $company) {
        $grades = array_keys($company['grades']);
        $sorted = $grades;
        usort($sorted, fn ($a, $b) => (float) $b <=> (float) $a);

        expect($grades)->toBe(
            $sorted,
            "{$company['name']}: grades must run best first",
        );

        $previous = null;

        foreach ($company['grades'] as $grade => $limits) {
            if ($previous !== null) {
                expect((float) $limits['front'])->toBeGreaterThanOrEqual(
                    $previous,
                    "{$company['name']} {$grade}: a lower grade cannot demand better centering than a higher one",
                );
            }

            $previous = (float) $limits['front'];
        }
    }
});

test('PSA is present, because it is the one anchor here that is verifiable', function () {
    $names = array_map(fn ($c) => $c['name'], CenteringStandards::all());

    expect($names)->toContain('PSA');
});
