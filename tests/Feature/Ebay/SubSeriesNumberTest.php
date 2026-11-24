<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Ebay\SoldCompClassifier;

function thirtiethCard(Set $set, string $number, string $name = 'Pikachu', string $language = 'en'): CatalogItem
{
    return CatalogItem::factory()->create([
        'set_id' => $set->id,
        'item_type' => ItemType::Single,
        'name' => $name,
        'number' => $number,
        'attributes' => ['language' => $language, 'variant' => 'holo'],
    ])->fresh();
}

beforeEach(function () {
    // A 128-card set with secret rares past it, like 30th Celebration.
    $this->set = Set::factory()->create(['name' => '30th Celebration']);
    thirtiethCard($this->set, '150', 'Pikachu ex');
    $this->classifier = app(SoldCompClassifier::class);
});

test('a sub-series number is not the collector number', function () {
    // The live failure: Pikachu 047/128 is the 25th of thirty Pikachus, and
    // "25/30" filed its sales under Pikachu #25.
    $title = 'Pikachu 047/128 25/30 30th Celebration Pokemon TCG English Holo';

    expect($this->classifier->titleRejectReason(thirtiethCard($this->set, '25'), $title))
        ->toBe('collector number does not match');
    expect($this->classifier->titleRejectReason(thirtiethCard($this->set, '47'), $title))
        ->toBeNull();
});

test('the card whose collector number it is keeps its sub-series listings', function () {
    expect($this->classifier->titleRejectReason(
        thirtiethCard($this->set, '25'),
        'Pokemon 30th Anniversary Pikachu 25/128 Pikachu Number 03/30',
    ))->toBeNull();
});

test('a reprint stating its original number as well is still a match', function () {
    // Larger than the set, so not a sub-series: the original printing's number.
    expect($this->classifier->titleRejectReason(
        thirtiethCard($this->set, '138', 'Lycanroc'),
        'Lycanroc - 138/128 Holofoil 087/198 ME: 30th Celebration Illustration Rare NM',
    ))->toBeNull();
});

test('a small set reads every fraction as before', function () {
    // Classic Collection runs to 25 cards; its reprints print the original
    // "11/113" and nothing is a sub-series.
    $classic = Set::factory()->create(['name' => 'Classic Collection']);

    expect($this->classifier->titleRejectReason(
        thirtiethCard($classic, '11', 'Metagross'),
        'Metagross (Delta Species) 11/113 30th Celebration TAG 10',
    ))->toBeNull();
});

test('a lone fraction is read even when the set runs far past its printed size', function () {
    // White Flare prints /086 and its art rares run to 173.
    $whiteFlare = Set::factory()->create(['name' => 'White Flare']);
    thirtiethCard($whiteFlare, '173', 'Victini');

    expect($this->classifier->titleRejectReason(
        thirtiethCard($whiteFlare, '112', 'Zebstrika', 'ja'),
        'Zebstrika 112/086 Art Rare SV11W White Flare Japanese Pokemon Holo NM/M 112/086',
    ))->toBeNull();
});

test('a secret rare is read even beside a bigger fraction', function () {
    // eBay titles carry a second, wrong number from the item specifics.
    $whiteFlare = Set::factory()->create(['name' => 'White Flare']);
    thirtiethCard($whiteFlare, '173', 'Victini');

    expect($this->classifier->titleRejectReason(
        thirtiethCard($whiteFlare, '91', 'Deerling'),
        'Pokemon Company Deerling 091/086 White Flare Illustration Rare Holo 70 HP EN 172/159',
    ))->toBeNull();
});
