<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\Set;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->item = CatalogItem::factory()->create([
        'name' => 'Pikachu ex', 'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'Illustration Rare', 'variant' => 'holo'],
    ]);
    $this->psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $this->companies = ['psa' => $this->psa->id];
    $this->classifier = new SoldCompClassifier;
    $this->anchor = 130_000; // $1,300 NM anchor
});

function candidate(string $title, int $cents): SoldCandidate
{
    return new SoldCandidate($title, $cents, CarbonImmutable::now()->subDays(3), '99'.$cents);
}

test('it accepts a genuine raw single as Near Mint', function () {
    $comp = $this->classifier->classify(candidate('Pikachu ex 276/217 SIR Ascended Heroes', 129000), $this->item, $this->anchor, $this->companies);

    expect($comp)->not->toBeNull()
        ->and($comp->condition)->toBe('NM')
        ->and($comp->gradingCompanyId)->toBeNull();
});

test('it classifies a graded title into the company/grade state', function () {
    $comp = $this->classifier->classify(candidate('2026 Pikachu ex 276/217 PSA 10 Ascended Heroes', 380000), $this->item, $this->anchor, $this->companies);

    expect($comp)->not->toBeNull()
        ->and($comp->gradingCompanyId)->toBe($this->psa->id)
        ->and($comp->grade)->toBe(10.0)
        ->and($comp->condition)->toBeNull();
});

test('it rejects the wrong printing and keeps the right one', function () {
    $unlimited = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo', 'edition' => 'unlimited']]);
    $firstEd = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo', 'edition' => 'first_edition']]);

    // Unlimited must NOT absorb a 1st Edition listing…
    expect($this->classifier->classify(candidate('Charizard 4 Base Set 1st Edition Holo', 500000), $unlimited, 380000, $this->companies))->toBeNull();
    // …but keeps a plain Base listing.
    expect($this->classifier->classify(candidate('Charizard 4 Base Set Holo', 40000), $unlimited, 38000, $this->companies))->not->toBeNull();

    // 1st Edition requires the stamp in the title.
    expect($this->classifier->classify(candidate('Charizard 4 Base Set Holo', 700000), $firstEd, 700000, $this->companies))->toBeNull();
    expect($this->classifier->classify(candidate('Charizard 4 1st Edition Base Set Holo', 700000), $firstEd, 700000, $this->companies))->not->toBeNull();
});

test('a base card rejects a reverse-holo listing', function () {
    $base = CatalogItem::factory()->create(['name' => 'Pikachu', 'number' => '58',
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'holo']]);

    expect($this->classifier->classify(candidate('Pikachu 58 Reverse Holo', 1000), $base, 1000, $this->companies))->toBeNull();
    expect($this->classifier->classify(candidate('Pikachu 58 Holo', 1000), $base, 1000, $this->companies))->not->toBeNull();
});

test('it rejects mystery boxes, lots, code cards, and wrong cards', function () {
    expect($this->classifier->classify(candidate('MYSTERY BOX chance at Pikachu ex 276/217', 999), $this->item, $this->anchor, $this->companies))->toBeNull();
    expect($this->classifier->classify(candidate('Lot of 50 Pokemon cards Pikachu ex bulk', 2500), $this->item, $this->anchor, $this->companies))->toBeNull();
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 PTCGO online code card', 100), $this->item, $this->anchor, $this->companies))->toBeNull();
    expect($this->classifier->classify(candidate('Charizard ex 199/165 PSA 10', 380000), $this->item, $this->anchor, $this->companies))->toBeNull(); // name mismatch
});

test('it rejects prices far outside the anchor band', function () {
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 SIR', 100), $this->item, $this->anchor, $this->companies))->toBeNull(); // way below
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 SIR', 99_999_99), $this->item, $this->anchor, $this->companies))->toBeNull(); // way above
});

test('it rejects multi-card set / bundle listings', function () {
    $charmander = CatalogItem::factory()->create(['name' => 'Charmander', 'number' => '38',
        'attributes' => ['language' => 'en', 'rarity' => 'Promo', 'variant' => 'holo']]);

    // 3 distinct collector numbers => the full set.
    expect($this->classifier->classify(candidate('First Partners Bulbasaur Charmander Squirtle 37 38 39 Promo', 8900), $charmander, 0, $this->companies))->toBeNull();
    // "+"-joined bundle.
    expect($this->classifier->classify(candidate('Charmander 038 + Squirtle 039 + Bulbasaur 037 Pokemon', 145000), $charmander, 0, $this->companies))->toBeNull();
    // comma-separated number list.
    expect($this->classifier->classify(candidate('Charmander First Partner 37, 38, 39 PSA 10', 90000), $charmander, 0, $this->companies))->toBeNull();
    // a genuine single is still accepted.
    expect($this->classifier->classify(candidate('Charmander 038 First Partner Illustration', 5000), $charmander, 0, $this->companies))->not->toBeNull();
});

test('it rejects a starter-set listing that names several cards from the same set', function () {
    $set = Set::factory()->create();
    $chikorita = CatalogItem::factory()->create(['name' => 'Chikorita', 'number' => '46', 'set_id' => $set->id,
        'attributes' => ['language' => 'en', 'variant' => 'holo']]);
    CatalogItem::factory()->create(['name' => 'Cyndaquil', 'number' => '47', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);
    CatalogItem::factory()->create(['name' => 'Totodile', 'number' => '48', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);

    // The starter-set listing names all three siblings — not a single-card comp.
    expect($this->classifier->classify(candidate('Pokemon First Partner Series 2 Johto Starter Set Chikorita Cyndaquil Totodile', 9000), $chikorita, 0, $this->companies))->toBeNull();
    // "Set of 3" language alone is enough.
    expect($this->classifier->classify(candidate('First Partner Series 2 Chikorita Set Of 3 Pokemon', 8500), $chikorita, 0, $this->companies))->toBeNull();
    // A genuine single Chikorita is still accepted.
    expect($this->classifier->classify(candidate('Pokemon First Partners Series 2 Chikorita 046 Promo', 3000), $chikorita, 0, $this->companies))->not->toBeNull();
});

test('a set name containing a number is not mistaken for a multi-card listing', function () {
    // "151" (set name) + the card's own 276 = two numbers, under the 3-number bar.
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 Pokemon 151 PSA 10', 380000), $this->item, $this->anchor, $this->companies))->not->toBeNull();
});

test('a graded sale far above the raw anchor is still accepted (graded bypass band)', function () {
    // $9,000 graded vs a $1,300 raw anchor = 6.9x — would fail the raw band, but
    // a graded premium is expected, so it must be ingested as the PSA 10 state.
    $comp = $this->classifier->classify(candidate('Pikachu ex 276/217 PSA 10 Ascended Heroes', 900000), $this->item, $this->anchor, $this->companies);

    expect($comp)->not->toBeNull()
        ->and($comp->gradingCompanyId)->toBe($this->psa->id)
        ->and($comp->grade)->toBe(10.0);
});

test('a raw sale far above the raw anchor is still rejected by the band', function () {
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 SIR Ascended Heroes', 900000), $this->item, $this->anchor, $this->companies))->toBeNull();
});

test('a Pokemon HP stat is not read as Heavily Played condition', function () {
    $gengar = CatalogItem::factory()->create(['name' => 'Gengar VMAX', 'number' => '271',
        'attributes' => ['language' => 'en', 'rarity' => 'Alt Art', 'variant' => 'holo']]);

    // "320 HP" is the card's hit points, not a condition.
    $comp = $this->classifier->classify(candidate('Gengar VMAX Alt Art 271/264 Fusion Strike 320 HP', 20000), $gengar, 0, $this->companies);
    expect($comp)->not->toBeNull()->and($comp->condition)->toBe('NM');

    // …but a real "Heavily Played" still classifies as HP.
    $played = $this->classifier->classify(candidate('Gengar VMAX 271/264 Heavily Played', 5000), $gengar, 0, $this->companies);
    expect($played->condition)->toBe('HP');
});

test('a base card rejects retailer-stamped promos, and a stamped card requires them', function () {
    // Base (unstamped) card must not absorb GameStop/EB Games/stamped sales —
    // they are a distinct printing (often many times the plain card's price).
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 GameStop Stamped Promo Ascended Heroes', 40000), $this->item, $this->anchor, $this->companies))->toBeNull();
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 EB Games Promo Ascended Heroes', 40000), $this->item, $this->anchor, $this->companies))->toBeNull();
    // A plain listing is still accepted.
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 SIR Ascended Heroes', 129000), $this->item, $this->anchor, $this->companies))->not->toBeNull();

    // A catalog item that IS the GameStop promo (stamp attribute) requires it.
    $promo = CatalogItem::factory()->create(['name' => 'Ho-Oh', 'number' => '10',
        'attributes' => ['language' => 'en', 'variant' => 'holo', 'stamp' => 'gamestop']]);
    expect($this->classifier->classify(candidate('Ho-Oh 010/086 GameStop Stamped Promo Chaos Rising', 6000), $promo, 5000, $this->companies))->not->toBeNull();
    expect($this->classifier->classify(candidate('Ho-Oh 010/086 Holo Chaos Rising', 150), $promo, 5000, $this->companies))->toBeNull();
});

test('a sealed booster bundle accepts a genuine bundle listing (blocklist bundle guard)', function () {
    // Regression: "bundle" is on the single-card blocklist; a Booster Bundle
    // product must not have every real comp rejected by it.
    $bundle = CatalogItem::factory()->sealed()->create([
        'name' => '151 Booster Bundle',
        'attributes' => ['language' => 'en', 'sealed_type' => 'booster_bundle'],
    ]);

    $comp = $this->classifier->classify(
        candidate('Pokemon Scarlet & Violet 151 Booster Bundle Factory Sealed', 3000),
        $bundle,
        2694,
        $this->companies,
    );

    expect($comp)->not->toBeNull()->and($comp->condition)->toBe('SEALED');
});

test('a sealed ETB rejects empty / opened / no-packs boxes', function () {
    $etb = CatalogItem::factory()->sealed()->create([
        'name' => '151 Elite Trainer Box',
        'attributes' => ['language' => 'en', 'sealed_type' => 'elite_trainer_box'],
    ]);
    $anchor = 56_000;

    // Collectible empties, not sealed sales — even priced within the band.
    expect($this->classifier->classify(candidate('Pokemon 151 Elite Trainer Box ETB EMPTY W Inserts NO PACKS', 12_000), $etb, $anchor, $this->companies))->toBeNull();
    expect($this->classifier->classify(candidate('Pokemon 151 Elite Trainer Box ETB Box Only No Cards', 9_000), $etb, $anchor, $this->companies))->toBeNull();

    // A genuine sealed ETB still passes…
    expect($this->classifier->classify(candidate('Pokemon Scarlet & Violet 151 Elite Trainer Box Factory Sealed', 56_000), $etb, $anchor, $this->companies))->not->toBeNull();
    // …and "Unopened" must NOT trip the 'opened' rule.
    expect($this->classifier->classify(candidate('Pokemon 151 Elite Trainer Box Factory Sealed Unopened', 57_000), $etb, $anchor, $this->companies))->not->toBeNull();
});

test('it parses Beckett, hyphenated, and word-separated grade forms', function () {
    $bgs = GradingCompany::factory()->create(['slug' => 'bgs', 'name' => 'Beckett (BGS)']);
    $companies = ['psa' => $this->psa->id, 'bgs' => $bgs->id];

    // "Beckett 9.5" => BGS 9.5
    $beckett = $this->classifier->classify(candidate('Pikachu ex 276/217 Beckett 9.5', 200000), $this->item, $this->anchor, $companies);
    expect($beckett->gradingCompanyId)->toBe($bgs->id)->and($beckett->grade)->toBe(9.5);

    // "PSA-10" (hyphen)
    $hyphen = $this->classifier->classify(candidate('Pikachu ex 276/217 PSA-10', 400000), $this->item, $this->anchor, $companies);
    expect($hyphen->grade)->toBe(10.0)->and($hyphen->gradingCompanyId)->toBe($this->psa->id);

    // "PSA GEM MINT 10" (grade words between company and number)
    $words = $this->classifier->classify(candidate('Pikachu ex 276/217 PSA GEM MINT 10', 400000), $this->item, $this->anchor, $companies);
    expect($words->grade)->toBe(10.0)->and($words->gradingCompanyId)->toBe($this->psa->id);
});
