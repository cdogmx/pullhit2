<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->item = CatalogItem::factory()->create(['name' => 'Pikachu ex', 'number' => '276/217']);
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
