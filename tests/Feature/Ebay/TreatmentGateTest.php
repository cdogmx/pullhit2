<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Ebay\SoldCompClassifier;

beforeEach(function () {
    $this->classifier = app(SoldCompClassifier::class);
});

/** A single with a known rarity. */
function ratedCard(?string $rarity, string $name = 'Gardevoir ex', string $number = '29'): CatalogItem
{
    $item = CatalogItem::factory()->create([
        'item_type' => ItemType::Single,
        'name' => $name,
        'number' => $number,
        'attributes' => array_filter([
            'language' => 'en', 'variant' => 'normal', 'rarity' => $rarity,
        ], fn ($v) => $v !== null),
    ]);

    // `rarity` is a generated column: it is not populated on the instance that
    // created the row, only on one read back. Everything in production reads
    // the item from the database, so re-read here too.
    return $item->fresh();
}

test('a chase treatment is rejected for a card that is not it', function () {
    // The live failure: this sold for $346 and became the PSA 10 price of a
    // Double Rare worth about a dollar raw. Its title states no number at all,
    // so the collector-number gate had nothing to judge.
    $title = '2024 POKEMON PAF EN-PALDEAN FATES SPECIAL ILLUSTRATION RARE GARDEVOIR EX PSA 10';

    expect($this->classifier->titleRejectReason(ratedCard('Double Rare'), $title))
        ->toBe('listing is a special illustration rare, this card is not');
});

test('the same listing is kept for the card it actually is', function () {
    $title = '2024 POKEMON PAF EN-PALDEAN FATES SPECIAL ILLUSTRATION RARE GARDEVOIR EX PSA 10';

    expect($this->classifier->titleRejectReason(ratedCard('Special Illustration Rare', number: '233'), $title))
        ->toBeNull();
});

test('a card that might itself be a chase printing is never judged on words', function () {
    // Our rarity data is the weak side of this comparison: a Neo Destiny
    // Shining Charizard is stored "Rare Shining" and really is the set's secret
    // rare; a Call of Legends SL10 is stored "Rare Holo" and really is one too.
    // Judging those on the title deleted a $15,995 sale from the very card it
    // belonged to, so the gate rules only on tiers that plainly are not chase.
    foreach (['Illustration Rare', 'Rare Shining', 'Rare Holo', 'Ultra Rare', 'Rare Secret'] as $chase) {
        expect($this->classifier->titleRejectReason(
            ratedCard($chase),
            'Pokemon Special Illustration Rare Gardevoir ex',
        ))->toBeNull("rarity: {$chase}");
    }
});

test('the specific treatment still wins over the general one', function () {
    // "special illustration rare" contains "illustration rare", so an SIR title
    // must be reported as an SIR rather than as a plain Illustration Rare.
    expect($this->classifier->titleRejectReason(
        ratedCard('Double Rare'),
        'Pokemon Special Illustration Rare Gardevoir ex',
    ))->toBe('listing is a special illustration rare, this card is not');
});

test('one tier spelled several ways still matches itself', function () {
    // Our vocabulary holds "Rare Secret", "SEC" and "Secret Rare" for the same
    // tier; comparing the phrase to the stored string would reject all but one.
    foreach (['Rare Secret', 'SEC', 'Secret Rare', 'Rare Rainbow'] as $rarity) {
        $card = ratedCard($rarity, name: 'Charizard ex', number: '200');

        expect($this->classifier->titleRejectReason($card, 'Charizard ex Secret Rare 200/195'))
            ->toBeNull("rarity: {$rarity}");
    }
});

test('a card whose rarity we do not hold is never judged on it', function () {
    // An unknown rarity cannot contradict anything, and guessing would reject
    // comps for exactly the cards with the least data on them.
    expect($this->classifier->titleRejectReason(ratedCard(null), 'Special Illustration Rare Gardevoir ex'))
        ->toBeNull();
});

test('an ordinary title is untouched by the gate', function () {
    foreach ([
        'Gardevoir ex 029/091 Double Rare SV: Paldean Fates Pokemon TCG - NM',
        'Pokemon Paldean Fates "Gardevoir ex" 29/91 / Double Rare / Near Mint',
        '2024 Pokemon Paldean Fates PAF EN 029 Gardevoir EX - PSA 9',
    ] as $title) {
        expect($this->classifier->titleRejectReason(ratedCard('Double Rare'), $title))
            ->toBeNull("title: {$title}");
    }
});

test('hyper rare and shiny ultra rare are gated the same way', function () {
    $plain = ratedCard('Double Rare', name: 'Charizard ex', number: '6');
    $hyper = ratedCard('Hyper Rare', name: 'Charizard ex', number: '223');
    $umbreon = ratedCard('Rare', name: 'Umbreon', number: '10');

    expect($this->classifier->titleRejectReason($plain, 'Charizard ex Hyper Rare gold'))
        ->toBe('listing is a hyper rare, this card is not')
        ->and($this->classifier->titleRejectReason($hyper, 'Charizard ex Hyper Rare gold'))
        ->toBeNull()
        ->and($this->classifier->titleRejectReason($umbreon, 'Umbreon Shiny Ultra Rare'))
        ->toBe('listing is a shiny ultra rare, this card is not');
});

test('a stated collector number outranks a stated treatment', function () {
    // Our own rarity is often the weaker fact. "Chaos Rising Illustration Rare"
    // at 090/086 really is one whatever we have stored, and judging the words
    // over the number rejected 3,638 comps on that pattern alone.
    $card = ratedCard('Rare', name: 'Ampharos', number: '90');

    expect($this->classifier->titleRejectReason($card, 'Ampharos - 090/086 - ME04: Chaos Rising (CRI) Illustration Rare'))
        ->toBeNull();
});

test('a number that belongs to another card is still rejected', function () {
    // Deferring to a stated number must not weaken the number gate itself.
    $card = ratedCard('Rare', name: 'Ampharos', number: '90');

    expect($this->classifier->titleRejectReason($card, 'Ampharos - 112/086 - Chaos Rising Illustration Rare'))
        ->toBe('collector number does not match');
});

test('the treatment gate still fires when the title states no number at all', function () {
    // The original failure: this states its treatment and never its number, so
    // there is nothing but the words to judge it on.
    $card = ratedCard('Double Rare');

    expect($this->classifier->titleRejectReason($card, '2024 POKEMON PAF EN-PALDEAN FATES SPECIAL ILLUSTRATION RARE GARDEVOIR EX PSA 10'))
        ->toBe('listing is a special illustration rare, this card is not');
});

test('the gate runs after the cheaper ones, so the clearest reason is reported', function () {
    // A lot listing that also names a treatment should read as a lot.
    expect($this->classifier->titleRejectReason(
        ratedCard('Double Rare'),
        'Pokemon lot of 20 cards Special Illustration Rare included',
    ))->toBe('blocklisted term “lot of”');
});
