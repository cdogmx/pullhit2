<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;

/**
 * A slab must never price the raw band.
 *
 * Both failures here were found on a live card: Celebrations Flying Pikachu V
 * carried a raw NM value of $53.35 against a real ungraded market of $4.28,
 * because a BCCG slab and a PSA-10-priced listing had been filed as raw cards.
 * The damage is quiet and compounding — the raw median is also the anchor the
 * price-sanity band is measured against, so once it is too high the band widens
 * and admits the next expensive slab too.
 */
beforeEach(function () {
    $this->item = CatalogItem::factory()->create([
        'name' => 'Pikachu ex', 'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'Illustration Rare', 'variant' => 'holo'],
    ]);
    $this->psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $this->companies = ['psa' => $this->psa->id];
    $this->classifier = new SoldCompClassifier;
});

function slabCandidate(string $title, int $cents = 150000): SoldCandidate
{
    return new SoldCandidate($title, $cents, CarbonImmutable::now()->subDays(3), 'id'.mt_rand());
}

test('"PSA graded 10" is a PSA 10, not a raw card', function () {
    // The live regex allowed gem/mint/pristine/black/label between the company
    // and the number but not the word "graded" — which is how sellers most
    // often write it. 267 stored comps across 237 cards had landed in raw.
    foreach ([
        'Lugia V 186/195 Silver Tempest English Pikachu ex PSA GRADED 10' => 10.0,
        'Pikachu ex 276/217 PSA Graded 8' => 8.0,
        'PSA Grade 8- Pikachu ex 276/217 Holo' => 8.0,
        'Pikachu ex 276/217 PSA graded 9.5' => 9.5,
    ] as $title => $grade) {
        $comp = $this->classifier->pricedState(slabCandidate($title), $this->companies);

        expect($comp->gradingCompanyId)->toBe($this->psa->id, $title)
            ->and($comp->grade)->toBe($grade, $title)
            ->and($comp->condition)->toBeNull($title);
    }
});

test('"PSA Graded 1st Edition" does not become a PSA 1', function () {
    // The obvious fix — allowing "graded" before the number — reads the 1 of
    // "1st" as the grade unless the number is required to end on a word
    // boundary. That would invent a PSA 1 out of a listing with no grade at all.
    $comp = $this->classifier->pricedState(
        slabCandidate('Pikachu ex PSA Graded 1st Edition Base Set Shadowless'),
        $this->companies,
    );

    expect($comp->grade)->not->toBe(1.0);
});

test('a slab from a company we do not track is rejected, not counted as raw', function () {
    // We hold six graders. The rest are not raw cards either, and their grades
    // are not comparable to PSA's — BCCG 10 is roughly a nice raw card, AGS and
    // MNT are newer and looser. Storing them as a graded band would imply we
    // can price them; storing them as raw is what broke Flying Pikachu V. So
    // they are dropped, which also lets the existing prune pass clear the 449
    // already sitting in raw bands.
    foreach ([
        'Pikachu ex 276/217. Celebrations Holo UR. BCCG 10 Graded.',
        'Pikachu ex 276/217 AGS 9.5 mint',
        'Pikachu ex 276/217 GMA 10 Gem Mint',
        'Pikachu ex 276/217 Graded Arena Club 10',
        'Pikachu ex 276/217 HGA 9',
        'Pikachu ex 276/217 KSA 8 NMM',
        'Pikachu ex 276/217 RCG 9.5',
        'ISA 8.5 Pikachu ex 276/217',
    ] as $title) {
        expect($this->classifier->structurallyInvalid(slabCandidate($title), $this->item))
            ->toBeTrue("{$title} should not be a comp");
    }
});

test('a genuine raw single is still accepted', function () {
    // The guard above must not swallow ordinary listings. "bags" contains ags
    // and "mint" is not a grading company however a seller abbreviates it.
    foreach ([
        'Pikachu ex 276/217 SIR Ascended Heroes',
        'Pikachu ex 276/217 Holo NM — ships in penny sleeve and bags',
        'Pikachu ex 276/217 Gem Mint condition, ungraded',
    ] as $title) {
        expect($this->classifier->structurallyInvalid(slabCandidate($title, 129000), $this->item))
            ->toBeFalse($title);
    }
});
