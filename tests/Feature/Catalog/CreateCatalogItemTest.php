<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->action = app(CreateCatalogItem::class);

    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->pokemon = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'pokemon',
    ]);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->pokemon->id,
        'code' => 'MEW',
        'language' => 'en',
    ]);
});

function createSingle(): CatalogItem
{
    return (test()->action)(
        vertical: test()->vertical,
        productLine: test()->pokemon,
        set: test()->set,
        itemType: ItemType::Single,
        name: 'Pikachu',
        number: '173/165',
        attributes: [
            'language' => 'en',
            'rarity' => 'Illustration Rare',
            'variant' => 'reverse_holo',
        ],
    );
}

test('it creates a catalog item with a populated generated language column', function () {
    $item = createSingle();

    expect($item->item_type)->toBe(ItemType::Single)
        ->and($item->attributes['rarity'])->toBe('Illustration Rare')
        ->and($item->identity_hash)->toHaveLength(64);

    // language is a generated column — read it back from the database.
    $item->refresh();
    expect($item->language)->toBe('en');

    expect(CatalogItem::where('language', 'en')->count())->toBe(1);
});

test('re-running with identical input dedups to a single row', function () {
    $first = createSingle();
    $second = createSingle();

    expect($second->id)->toBe($first->id);
    expect(CatalogItem::count())->toBe(1);
});

test('a different language produces a distinct catalog item', function () {
    $en = createSingle();

    $ja = ($this->action)(
        vertical: $this->vertical,
        productLine: $this->pokemon,
        set: $this->set,
        itemType: ItemType::Single,
        name: 'Pikachu',
        number: '173/165',
        attributes: [
            'language' => 'ja',
            'rarity' => 'Illustration Rare',
            'variant' => 'reverse_holo',
        ],
    );

    expect($ja->id)->not->toBe($en->id);
    expect(CatalogItem::count())->toBe(2);
});

test('invalid attributes throw before anything is persisted', function () {
    expect(fn () => ($this->action)(
        vertical: $this->vertical,
        productLine: $this->pokemon,
        set: $this->set,
        itemType: ItemType::Single,
        name: 'Pikachu',
        attributes: ['rarity' => 'Common'], // missing required language + variant
    ))->toThrow(ValidationException::class);

    expect(CatalogItem::count())->toBe(0);
});
