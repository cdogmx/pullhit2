<?php

use App\Support\Grading\SubmissionTiers;

/**
 * Checks on the prices typed into config/grading.php.
 *
 * Same reasoning as the centering tolerances: these are copied by hand from a
 * published price list, they go stale, and every way of getting them wrong is
 * silent. A cap in cents instead of dollars, or two rows out of order, still
 * returns a tier — just the wrong one, on a recommendation to spend $600.
 */
test('every configured company says where its prices came from', function () {
    foreach ((array) config('grading.submission_tiers') as $key => $company) {
        if (($company['tiers'] ?? []) === []) {
            continue; // Not filled in yet, which is allowed — see the config.
        }

        expect($company['name'] ?? null)->toBeString()->not->toBe('')
            // A price with no source is one nobody can re-check when it moves.
            ->and($company['source'] ?? null)->toBeString()->not->toBe('', "{$key} has prices but no source");
    }
});

test('every tier has a fee, a turnaround and a name', function () {
    foreach ((array) config('grading.submission_tiers') as $company) {
        foreach ($company['tiers'] ?? [] as $tier) {
            $where = ($company['name'] ?? '?').' '.($tier['name'] ?? '?');

            expect($tier['name'] ?? null)->toBeString()->not->toBe('')
                ->and($tier['fee'] ?? null)->toBeNumeric("{$where} has no fee")
                ->toBeGreaterThan(0, "{$where} is free, which no grader is")
                // The turnaround is half of why someone picks a tier, and it
                // is shown next to the price, so a blank one is a broken quote.
                ->and($tier['turnaround'] ?? null)->toBeString("{$where} has no turnaround")->not->toBe('');
        }
    }
});

test('caps rise with price, so the cheapest eligible tier is always reachable', function () {
    // The selection walks cheapest-first and stops at the first tier whose cap
    // fits. If a dearer tier had a LOWER cap it would be unreachable — nothing
    // would error, that service level would just silently never be quoted.
    foreach ((array) config('grading.submission_tiers') as $key => $company) {
        $tiers = SubmissionTiers::for($key);
        $previous = 0;

        foreach ($tiers as $i => $tier) {
            $cap = $tier['max_insured_value'] ?? null;

            if ($cap === null) {
                expect($i)->toBe(
                    count($tiers) - 1,
                    "{$company['name']} {$tier['name']}: an uncapped tier must be the dearest, or it swallows everything above it",
                );

                continue;
            }

            expect((float) $cap)->toBeGreaterThan(
                $previous,
                "{$company['name']} {$tier['name']}: costs more than the tier below it but accepts less",
            );

            $previous = (float) $cap;
        }
    }
});

test('PSA quotes its real published levels', function () {
    // Anchored to the price list as published for the United States. When PSA
    // moves its prices this test fails, which is the point — it is a prompt to
    // go and re-read the page, not a bug.
    expect(SubmissionTiers::cheapestFor(50_000)['name'])->toBe('Standard')      // $500 card
        ->and(SubmissionTiers::cheapestFor(120_000)['name'])->toBe('Priority')  // $1,200
        ->and(SubmissionTiers::cheapestFor(200_000)['name'])->toBe('Express')   // $2,000
        ->and(SubmissionTiers::cheapestFor(400_000)['name'])->toBe('Super Express')
        ->and(SubmissionTiers::cheapestFor(800_000)['name'])->toBe('Premier')
        ->and(SubmissionTiers::cheapestFor(5_000_000)['name'])->toBe('Premium');
});

test('a cheap card is quoted the cheap tier, not the flat fee', function () {
    // The whole reason tiers exist here: the flat fee was a guess, and on a
    // $30 card the fee IS the decision.
    $cost = SubmissionTiers::costFor(3000);

    expect($cost['tier'])->toBe('Standard')
        ->and($cost['per_card'])->toBe(5999)
        ->and($cost['turnaround'])->toContain('business days');
});
