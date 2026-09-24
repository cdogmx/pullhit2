<?php

use App\Support\Grading\SubmissionTiers;

/**
 * The tier is picked by the card's value, not by the owner's preference, so
 * the only thing to get right here is which tier a given value lands in — and
 * the failure mode is quiet: quote too cheap a tier and the advisor recommends
 * sending a card at a price the company will not honour.
 */
function tierConfig(array $overrides = []): array
{
    return array_merge([
        'fee' => 25,
        'shipping' => 10,
        'submission_tiers' => [
            'psa' => [
                'name' => 'PSA',
                'source' => 'test',
                'tiers' => [
                    ['name' => 'Cheap', 'fee' => 20, 'max_insured_value' => 500, 'turnaround' => '60 days'],
                    ['name' => 'Mid', 'fee' => 50, 'max_insured_value' => 2500, 'turnaround' => '20 days'],
                    ['name' => 'Top', 'fee' => 400, 'max_insured_value' => null, 'turnaround' => '5 days'],
                ],
            ],
        ],
    ], $overrides);
}

test('a card takes the cheapest tier that will accept it', function () {
    $tier = SubmissionTiers::cheapestFor(30000, 'psa', tierConfig()); // $300

    expect($tier['name'])->toBe('Cheap');
});

test('the cap is inclusive — a card worth exactly the limit still fits', function () {
    // The boundary is where an off-by-one lives, and here it would push every
    // card at a round number up a tier and quote too much.
    expect(SubmissionTiers::cheapestFor(50000, 'psa', tierConfig())['name'])->toBe('Cheap')
        ->and(SubmissionTiers::cheapestFor(50001, 'psa', tierConfig())['name'])->toBe('Mid');
});

test('a card worth more than any capped tier goes to the uncapped one', function () {
    $tier = SubmissionTiers::cheapestFor(9_000_000, 'psa', tierConfig()); // $90,000

    expect($tier['name'])->toBe('Top');
});

test('tiers are ordered by fee regardless of how they were typed in', function () {
    $config = tierConfig(['submission_tiers' => ['psa' => ['tiers' => [
        ['name' => 'Top', 'fee' => 400, 'max_insured_value' => null],
        ['name' => 'Cheap', 'fee' => 20, 'max_insured_value' => 500],
    ]]]]);

    // Cheapest-first is what makes "the first one that fits" the right answer.
    // Listed the other way round, every card would take the expensive tier.
    expect(SubmissionTiers::cheapestFor(1000, 'psa', $config)['name'])->toBe('Cheap');
});

test('cost is the tier fee plus shipping, and says which tier it used', function () {
    $cost = SubmissionTiers::costFor(30000, 'psa', tierConfig());

    expect($cost['cents'])->toBe(3000) // $20 + $10 shipping
        ->and($cost['per_card'])->toBe(2000)
        ->and($cost['shipping'])->toBe(1000)
        ->and($cost['tier'])->toBe('Cheap')
        ->and($cost['turnaround'])->toBe('60 days');
});

test('a company with no tiers falls back to the flat fee', function () {
    // This is what every non-PSA company does today, and the advisor has to
    // keep working rather than costing grading at zero.
    $cost = SubmissionTiers::costFor(30000, 'bgs', tierConfig());

    expect($cost['cents'])->toBe(3500) // flat $25 + $10
        ->and($cost['tier'])->toBeNull();
});
