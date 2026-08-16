<?php

use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\SearchCatalog;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Rarity;
use App\Models\Set;
use App\Models\Vertical;
use Database\Seeders\RaritySeeder;

beforeEach(function () {
    $this->seed(RaritySeeder::class);

    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'pokemon',
    ]);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->line->id,
        'slug' => 'a-set',
        'language' => 'en',
    ]);
});

function cardWithRarity(string $rarity, string $number): void
{
    app(CreateCatalogItem::class)(
        vertical: test()->vertical,
        productLine: test()->line,
        set: test()->set,
        itemType: ItemType::Single,
        name: 'Card '.$number,
        number: $number,
        attributes: ['language' => 'en', 'variant' => 'normal', 'rarity' => $rarity],
    );
}

test('options are ordered common to chase, not alphabetically', function () {
    cardWithRarity('Hyper Rare', '1');
    cardWithRarity('Common', '2');
    cardWithRarity('Illustration Rare', '3');
    cardWithRarity('Uncommon', '4');

    $labels = collect(app(CatalogFilterOptions::class)([])['rarities'])->pluck('label')->all();

    // Alphabetically this would be Common, Hyper Rare, Illustration Rare, Uncommon.
    expect($labels)->toBe(['Common', 'Uncommon', 'Illustration Rare', 'Hyper Rare']);
});

test('abbreviations are shown as words but filtered by their raw value', function () {
    cardWithRarity('SEC', '1');
    cardWithRarity('UC', '2');

    $options = collect(app(CatalogFilterOptions::class)([])['rarities']);

    expect($options->firstWhere('value', 'UC')['label'])->toBe('Uncommon')
        ->and($options->firstWhere('value', 'SEC')['label'])->toBe('Secret Rare');
});

test('a Japanese rarity keeps its own option rather than folding into the English one', function () {
    cardWithRarity('Illustration Rare', '1');
    cardWithRarity('Art Rare', '2');

    $options = collect(app(CatalogFilterOptions::class)([])['rarities']);

    // Same slot in two markets, not the same card — they must stay separable.
    expect($options)->toHaveCount(2)
        ->and($options->firstWhere('value', 'Art Rare')['label'])->toBe('Art Rare (JP)')
        ->and($options->firstWhere('value', 'Illustration Rare')['label'])->toBe('Illustration Rare');
});

test('placeholder rarities are kept out of the dropdown but stay filterable', function () {
    cardWithRarity('None', '1');
    cardWithRarity('Unknown', '2');
    cardWithRarity('Rare', '3');

    $options = collect(app(CatalogFilterOptions::class)([])['rarities']);

    expect($options->pluck('value')->all())->toBe(['Rare']);

    // Hiding the option must not hide the cards — a direct filter still works.
    expect(app(SearchCatalog::class)(['rarity' => 'None'])->total())->toBe(1);
});

test('an unclassified rarity still appears, labelled as it arrived', function () {
    cardWithRarity('Brand New Rarity From A Future Set', '1');
    cardWithRarity('Common', '2');

    $options = collect(app(CatalogFilterOptions::class)([])['rarities']);

    // It sorts last rather than vanishing — a filter that silently drops cards
    // is worse than one showing a raw label.
    expect($options->pluck('label')->all())
        ->toBe(['Common', 'Brand New Rarity From A Future Set']);
});

test('every rarity in the catalog has a mapping seeded', function () {
    // Guards the seeder against drifting behind the data it describes.
    $seeded = Rarity::pluck('value');

    expect($seeded)->toContain('Common', 'SR', 'Kagayaku', 'Special Illustration Rare')
        ->and(Rarity::where('is_hidden', true)->pluck('value')->all())
        ->toContain('None', 'Unknown');
});
