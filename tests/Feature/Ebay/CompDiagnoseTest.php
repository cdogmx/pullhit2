<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\User;
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

function diag(string $title, int $cents): SoldCandidate
{
    return new SoldCandidate($title, $cents, CarbonImmutable::now()->subDays(3), 'id'.$cents);
}

test('a genuine raw single diagnoses as ingest into NM', function () {
    $d = $this->classifier->diagnose(diag('Pikachu ex 276/217 SIR Ascended Heroes', 129000), $this->item, $this->anchor, $this->companies);

    expect($d['verdict'])->toBe('ingest')
        ->and($d['reason'])->toBeNull()
        ->and($d['state'])->toBe('NM');
});

test('a graded title diagnoses as ingest into the grade state', function () {
    $d = $this->classifier->diagnose(diag('Pikachu ex 276/217 PSA 10 Ascended Heroes', 380000), $this->item, $this->anchor, $this->companies);

    expect($d['verdict'])->toBe('ingest')
        ->and($d['state'])->toBe('PSA 10');
});

test('a lot diagnoses as reject with the multi-quantity reason', function () {
    $d = $this->classifier->diagnose(diag('Pikachu ex 276/217 Ascended Heroes playset', 2500), $this->item, $this->anchor, $this->companies);

    expect($d['verdict'])->toBe('reject')
        ->and($d['reason'])->toBe('multi-quantity lot')
        ->and($d['state'])->toBeNull();
});

test('a wrong-card title diagnoses as reject', function () {
    $d = $this->classifier->diagnose(diag('Charizard 4 Base Set Holo', 20000), $this->item, $this->anchor, $this->companies);

    expect($d['verdict'])->toBe('reject')
        ->and($d['reason'])->toBe('title does not name this card');
});

test('a wildly-priced raw comp diagnoses as reject on the price band', function () {
    // Names the card, right printing, but 100× the anchor → out of band.
    $d = $this->classifier->diagnose(diag('Pikachu ex 276/217 Ascended Heroes', 13_000_000), $this->item, $this->anchor, $this->companies);

    expect($d['verdict'])->toBe('reject')
        ->and($d['reason'])->toBe('price outside sanity band')
        ->and($d['state'])->toBe('NM'); // resolved state still reported
});

test('diagnose agrees with classify on every verdict', function () {
    $cases = [
        diag('Pikachu ex 276/217 Ascended Heroes', 129000),      // ingest
        diag('Pikachu ex 276/217 PSA 10', 380000),               // ingest (graded)
        diag('Lot of 50 Pokemon cards Pikachu ex', 2500),        // reject (lot)
        diag('Charizard 4 Base Set Holo', 20000),                // reject (wrong card)
    ];

    foreach ($cases as $c) {
        $classified = $this->classifier->classify($c, $this->item, $this->anchor, $this->companies) !== null;
        $diagnosed = $this->classifier->diagnose($c, $this->item, $this->anchor, $this->companies)['verdict'] === 'ingest';
        expect($diagnosed)->toBe($classified);
    }
});

test('the comp-preview endpoint is admin-only', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get("/admin/cards/{$this->item->id}/comp-preview")
        ->assertForbidden();
});
