<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;

function sealed(string $name, string $type): CatalogItem
{
    return CatalogItem::factory()->create([
        'name' => $name,
        'number' => null,
        'item_type' => ItemType::Sealed,
        'attributes' => ['sealed_type' => $type, 'language' => 'en'],
    ]);
}

function cand(string $title, int $cents): SoldCandidate
{
    return new SoldCandidate($title, $cents, CarbonImmutable::now()->subDays(2), 's'.$cents);
}

beforeEach(function () {
    $this->classifier = new SoldCompClassifier;
    $this->companies = [];
});

test('a regular ETB rejects Pokemon Center and Case listings, keeps the real one', function () {
    $etb = sealed('151 Elite Trainer Box', 'elite_trainer_box');
    $anchor = 5000; // ~$50 seed

    // Pokémon Center 151 ETB — a different, pricier product.
    expect($this->classifier->classify(cand('Pokemon Center 151 MEW EN Elite Trainer Box Sealed', 130000), $etb, $anchor, $this->companies))->toBeNull();
    // A case of ETBs.
    expect($this->classifier->classify(cand('Pokemon 151 Elite Trainer Box Case Sealed', 55000), $etb, $anchor, $this->companies))->toBeNull();
    // The genuine regular ETB.
    expect($this->classifier->classify(cand('Pokemon TCG Scarlet & Violet 151 Elite Trainer Box Factory Sealed', 5500), $etb, $anchor, $this->companies))->not->toBeNull();
});

test('a Pokemon Center ETB Plus rejects the non-PC regular ETB', function () {
    $plus = sealed('Crown Zenith Pokemon Center Elite Trainer Box Plus', 'elite_trainer_box');
    $anchor = 55000;

    // Non-PC regular Crown Zenith ETB.
    expect($this->classifier->classify(cand('Crown Zenith Elite Trainer Box ETB Factory Sealed', 30000), $plus, $anchor, $this->companies))->toBeNull();
    // The genuine PC ETB Plus.
    expect($this->classifier->classify(cand('Pokemon Center Crown Zenith Elite Trainer Box Plus Sealed', 55000), $plus, $anchor, $this->companies))->not->toBeNull();
});

test('a Booster Box rejects an ETB listing and a 2-box bundle', function () {
    $box = sealed('Surging Sparks Booster Box', 'booster_box');
    $anchor = 30000;

    expect($this->classifier->classify(cand('Surging Sparks Elite Trainer Box Sealed', 12000), $box, $anchor, $this->companies))->toBeNull(); // wrong type
    expect($this->classifier->classify(cand('Surging Sparks Booster Box x2 Sealed', 60000), $box, $anchor, $this->companies))->toBeNull(); // multi-qty
    expect($this->classifier->classify(cand('Pokemon Surging Sparks Booster Box Factory Sealed', 30000), $box, $anchor, $this->companies))->not->toBeNull();
});

test('a Booster Bundle keeps listings that state its pack count, rejects real lots', function () {
    $bundle = sealed('Black Bolt Booster Bundle', 'booster_bundle');
    $anchor = 7000;

    // "6 Packs" is the bundle's CONTENTS, not a quantity — must ingest.
    foreach ([
        'Pokemon TCG Scarlet & Violet Black Bolt Booster Bundle - 6 Packs! NEW! SEALED!',
        'Pokemon Scarlet & Violet Black Bolt Booster Bundle 6 Packs Sealed New TCG',
        'Pokemon TCG Scarlet & Violet Black Bolt Booster Bundle (6 Packs) EN',
        'Pokemon TCG Scarlet & Violet Black Bolt Booster Bundle 6 Packs English 2025',
    ] as $title) {
        expect($this->classifier->classify(cand($title, 7200), $bundle, $anchor, $this->companies))
            ->not->toBeNull("should ingest: {$title}");
    }

    // Genuine multi-quantity — a count of the bundle's OWN unit, a multiplier.
    expect($this->classifier->classify(cand('Black Bolt Booster Bundle x2 Sealed', 14000), $bundle, $anchor, $this->companies))->toBeNull();
    expect($this->classifier->classify(cand('Lot of 3 Black Bolt Booster Bundle Sealed', 21000), $bundle, $anchor, $this->companies))->toBeNull();
    expect($this->classifier->classify(cand('2 Bundles Black Bolt Booster Sealed', 14000), $bundle, $anchor, $this->companies))->toBeNull();
});

test('a Booster Box keeps its internal pack count (36 packs), rejects a box count', function () {
    $box = sealed('Prismatic Evolutions Booster Box', 'booster_box');
    $anchor = 30000;

    // A box legitimately holds 36 packs — the pack count is contents.
    expect($this->classifier->classify(cand('Prismatic Evolutions Booster Box 36 Packs Factory Sealed', 32000), $box, $anchor, $this->companies))->not->toBeNull();
    // Two boxes is a lot.
    expect($this->classifier->classify(cand('Prismatic Evolutions 2 Booster Boxes Sealed', 60000), $box, $anchor, $this->companies))->toBeNull();
});

test('a sealed comp far outside the band is rejected', function () {
    $box = sealed('Surging Sparks Booster Box', 'booster_box');

    expect($this->classifier->classify(cand('Surging Sparks Booster Box Sealed', 500000), $box, 30000, $this->companies))->toBeNull();
});

test('a product mis-typed as booster_box still matches by its own name (Collection)', function () {
    // Real trap: admin defaults the sealed type to booster_box, but the product
    // is an "Illustration Collection". The name's own type word rescues it.
    $item = sealed('First Partner Illustration Collection - Series 2', 'booster_box');

    // Real collection listings — accepted via the name-inferred type.
    expect($this->classifier->classify(cand('Pokemon First Partner Illustration Collection Series 2 Sealed', 4500), $item, 0, $this->companies))->not->toBeNull();
    expect($this->classifier->classify(cand('Pokemon TCG First Partner Illustration Collection Series 2', 3000), $item, 0, $this->companies))->not->toBeNull();
    // Still rejects a multi-box lot.
    expect($this->classifier->classify(cand('5 x First Partner Illustration Collection Series 2', 20000), $item, 0, $this->companies))->toBeNull();
});
