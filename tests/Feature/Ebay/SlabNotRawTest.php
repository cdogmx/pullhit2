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

test('a fan-made card is not a comp for the real one', function () {
    // These sell for a few dollars under the real card's name. One of them put
    // Lugia EX at $3.88 against a $137 reference.
    foreach ([
        'Pikachu ex Pokemon Bubbles Friend Fan Art Non Tcg Fan Art Card 2025',
        'Pikachu ex 276/217 Fanart Holo Custom',
        'Pikachu ex 276/217 Non-TCG Art Card',
    ] as $title) {
        expect($this->classifier->structurallyInvalid(slabCandidate($title, 400), $this->item))
            ->toBeTrue($title);
    }

    // And the real card still passes.
    expect($this->classifier->structurallyInvalid(slabCandidate('Pikachu ex 276/217 SIR Ascended Heroes', 129000), $this->item))
        ->toBeFalse();
});

test('two collector numbers in slashed form are two cards', function () {
    // Real titles from the stored comps. statesTwoCardNumbers only recognised
    // the "#107" form — its lookahead explicitly excluded a number followed by
    // a slash, which is how most modern listings write one.
    foreach ([
        'Pikachu ex 276/217 & Pikachu ex 73/86 Chaos Rising IR & EX NM',
        'Pikachu ex 276/217 UR & Pikachu ex 118/086 FA',
    ] as $title) {
        expect($this->classifier->structurallyInvalid(slabCandidate($title, 800), $this->item))
            ->toBeTrue($title);
    }
});

test('a set name carrying a number is not a second card', function () {
    // The reason bare numbers are NOT counted. "Scarlet & Violet 151" puts a
    // number next to an ampersand in a perfectly ordinary single-card title,
    // and counting it would reject every 151 listing in the catalog.
    foreach ([
        'Pikachu ex 276/217 Sv: Scarlet & Violet 151 Holo',
        'Pokemon TCG Scarlet & Violet 151 MEW EN 276/217 Pikachu ex SIR',
        'Pikachu ex 276/217 Chaos Rising CRI Double Rare EN - NM & SHIPS FAST',
        'Pikachu ex 276/217 IR - Chaos Rising - Sleeved and Top Loaded',
        'Pokémon Chaos Rising - Pikachu ex CRI 102 - Full Art And Double Rare',
    ] as $title) {
        expect($this->classifier->structurallyInvalid(slabCandidate($title, 129000), $this->item))
            ->toBeFalse($title);
    }
});

test('our card paired with one other from its set is a bundle', function () {
    // The last bad comp on Celebrations Flying Pikachu V: "Surfing Pikachu V
    // and Flying Pikachu V" at $12 for the pair. The sibling gate needs two
    // OTHER cards named and this names one, and no number rule can help
    // because the title states no collector number at all.
    $set = \App\Models\Set::factory()->create(['name' => 'Celebrations']);
    $ours = CatalogItem::factory()->create([
        'set_id' => $set->id, 'name' => 'Flying Pikachu V', 'number' => '6',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);
    CatalogItem::factory()->create([
        'set_id' => $set->id, 'name' => 'Surfing Pikachu V', 'number' => '7',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    expect($this->classifier->structurallyInvalid(
        slabCandidate('Surfing Pikachu V and Flying Pikachu V - Pokemon TCG Celebrations 2021 - NM', 1200), $ours
    ))->toBeTrue();

    // And the ordinary single still passes, including when its description
    // happens to use the word "and".
    foreach ([
        'Flying Pikachu V 006/025 Celebrations Holo NM',
        'Flying Pikachu V 006/025 Celebrations Holo - Sleeved and Top Loaded',
        'Flying Pikachu V 006/025 Celebrations - ships with tracking and a sleeve',
    ] as $title) {
        expect($this->classifier->structurallyInvalid(slabCandidate($title, 500), $ours))->toBeFalse($title);
    }
});
