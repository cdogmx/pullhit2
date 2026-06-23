<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
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

test('a set name containing a number is not mistaken for a multi-card listing', function () {
    // "151" (set name) + the card's own 276 = two numbers, under the 3-number bar.
    expect($this->classifier->classify(candidate('Pikachu ex 276/217 Pokemon 151 PSA 10', 380000), $this->item, $this->anchor, $this->companies))->not->toBeNull();
});
