<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\CatalogItemSlugAlias;
use App\Models\CollectionItem;
use App\Models\ProductLine;
use App\Models\SaleObservation;
use App\Models\Set;
use App\Models\Vertical;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->create(['slug' => 'lorcana', 'vertical_id' => $this->vertical->id]);
    $this->set = Set::factory()->create(['product_line_id' => $this->line->id, 'slug' => 'hyperia-city']);
});

function lorcanaCard($test, string $rarity, string $variant, string $number = '241'): CatalogItem
{
    return CatalogItem::factory()->create([
        'vertical_id' => $test->vertical->id,
        'product_line_id' => $test->line->id,
        'set_id' => $test->set->id,
        'item_type' => ItemType::Single,
        'name' => "Mickey Mouse - {$rarity}",
        'number' => $number,
        'attributes' => ['language' => 'en', 'variant' => $variant, 'rarity' => $rarity],
    ])->fresh();
}

test('generate-foils makes no twin for a rarity printed only in foil', function () {
    foreach (['Enchanted', 'Epic', 'Iconic'] as $i => $rarity) {
        lorcanaCard($this, $rarity, 'normal', (string) (240 + $i));
    }
    lorcanaCard($this, 'Rare', 'normal', '23');

    $this->artisan('catalog:generate-foils', ['--set' => 'hyperia-city'])->assertSuccessful();

    expect(CatalogItem::where('variant', 'foil')->pluck('rarity')->all())->toBe(['Rare']);
});

test('a foil-only twin folds into its card: real sales move, seeds go, the URL stays', function () {
    $card = lorcanaCard($this, 'Iconic', 'normal');
    $twin = lorcanaCard($this, 'Iconic', 'foil');
    $owner = CollectionItem::factory()->create(['catalog_item_id' => $twin->id]);

    SaleObservation::factory()->create(['catalog_item_id' => $card->id, 'source_listing_id' => 'shared', 'observed_at' => now()->subDay()]);
    SaleObservation::factory()->create(['catalog_item_id' => $twin->id, 'source_listing_id' => 'shared', 'observed_at' => now()->subDay()]);
    SaleObservation::factory()->create(['catalog_item_id' => $twin->id, 'source_listing_id' => 'own', 'observed_at' => now()->subDay()]);
    SaleObservation::factory()->create(['catalog_item_id' => $twin->id, 'is_synthetic' => true, 'source_listing_id' => 'syn-1']);

    $this->artisan('catalog:merge-foil-only-twins')->assertSuccessful();

    expect(CatalogItem::find($twin->id))->toBeNull()
        ->and(SaleObservation::where('catalog_item_id', $card->id)->pluck('source_listing_id')->sort()->values()->all())->toBe(['own', 'shared'])
        ->and($owner->fresh()->catalog_item_id)->toBe($card->id)
        ->and(CatalogItemSlugAlias::where('slug', $twin->slug)->value('catalog_item_id'))->toBe($card->id);
});

test('a real foil printing is not merged', function () {
    lorcanaCard($this, 'Rare', 'normal', '23');
    $foil = lorcanaCard($this, 'Rare', 'foil', '23');

    $this->artisan('catalog:merge-foil-only-twins')->assertSuccessful();

    expect(CatalogItem::find($foil->id))->not->toBeNull();
});
