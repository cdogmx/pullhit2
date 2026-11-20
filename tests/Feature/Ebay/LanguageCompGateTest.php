<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Ebay\SoldCompClassifier;

beforeEach(function () {
    $this->classifier = app(SoldCompClassifier::class);
});

function languagedCard(string $language, string $name = 'Zekrom ex', string $number = '172', ?Set $set = null): CatalogItem
{
    return CatalogItem::factory()->create(array_filter([
        'item_type' => ItemType::Single,
        'name' => $name,
        'number' => $number,
        'set_id' => $set?->id,
        'attributes' => ['language' => $language, 'variant' => 'holo'],
    ], fn ($v) => $v !== null))->fresh();
}

test('a sold comp naming another language is rejected for an English card', function () {
    // The live failure: this sat in the raw NM pool of the English Black Bolt
    // Zekrom ex #172.
    expect($this->classifier->titleRejectReason(
        languagedCard('en'),
        '2025 Pokemon Spanish Zekrom EX 172/086 Black Bolt BWR',
    ))->toBe('listing is a Spanish printing, this card is not');

    expect($this->classifier->titleRejectReason(
        languagedCard('en'),
        'Zekrom ex 172/086 Black Bolt Japanese PSA 10',
    ))->toBe('listing is a Japanese printing, this card is not');
});

test('an English card with no language in the title is kept', function () {
    expect($this->classifier->titleRejectReason(
        languagedCard('en'),
        '2025 POKEMON BLK EN-BLACK BOLT BLACK WHITE RARE #172 ZEKROM EX PSA 9',
    ))->toBeNull();
});

test('an English card whose title says English is kept wherever it shipped from', function () {
    expect($this->classifier->titleRejectReason(
        languagedCard('en', 'Sanji', 'P-034'),
        'Sanji P-034 Championship Event Pack 2023 Promo English One Piece Card Japan',
    ))->toBeNull();
});

test('a Japanese box is not a comp for the English box of the same name', function () {
    // The live failure: the 1999 English Team Rocket box was priced at $130.95
    // from sales of the 2025 Japanese Glory of Team Rocket box.
    $box = CatalogItem::factory()->create([
        'item_type' => ItemType::Sealed,
        'name' => 'Team Rocket Booster Box [Unlimited Edition]',
        'attributes' => ['language' => 'en'],
    ])->fresh();

    expect($this->classifier->titleRejectReason(
        $box,
        'Pokemon TCG Glory of Team Rocket SV10 Booster Box Japanese Sealed',
    ))->toBe('listing is a Japanese printing, this card is not');
});

test('a Japanese card is not required to say Japanese, but is rejected for another language', function () {
    // Sold titles of a Japanese card often omit the word — the search already
    // pinned the language — so only a contradiction rejects.
    $card = languagedCard('ja');

    expect($this->classifier->titleRejectReason($card, 'Zekrom ex 172 Black Bolt PSA 10'))->toBeNull();
    expect($this->classifier->titleRejectReason($card, 'Zekrom ex 172 Black Bolt Japanese PSA 10'))->toBeNull();
    expect($this->classifier->titleRejectReason($card, 'Zekrom ex 172 Black Bolt Chinese PSA 10'))
        ->toBe('listing is a Chinese printing, this card is not');
});

test('a language word that is part of the card or set name is not a tell', function () {
    $set = Set::factory()->create(['name' => 'Japanese Exclusive Promos']);

    expect($this->classifier->titleRejectReason(
        languagedCard('en', 'Pikachu', '1', $set),
        'Pikachu 1 Japanese Exclusive Promos holo',
    ))->toBeNull();
});
