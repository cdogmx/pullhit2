<?php

use App\Actions\Catalog\SuggestSearch;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;

test('it suggests the closest card name for a typo', function () {
    CatalogItem::factory()->create(['name' => 'Charizard ex', 'item_type' => ItemType::Single, 'popularity' => 100]);

    expect(app(SuggestSearch::class)->didYouMean('charizrd'))->toBe('Charizard ex');
});

test('it suggests a set name typo', function () {
    $line = ProductLine::factory()->create(['slug' => 'lorcana', 'name' => 'Disney Lorcana']);
    Set::factory()->for($line)->create(['name' => 'Winterspell']);

    expect(app(SuggestSearch::class)->didYouMean('wintrspell'))->toBe('Winterspell');
});

test('it returns null for an exact match, a too-short query, or gibberish', function () {
    CatalogItem::factory()->create(['name' => 'Lugia', 'item_type' => ItemType::Single]);

    expect(app(SuggestSearch::class)->didYouMean('Lugia'))->toBeNull()      // exact — not a correction
        ->and(app(SuggestSearch::class)->didYouMean('lu'))->toBeNull()      // too short to fuzz
        ->and(app(SuggestSearch::class)->didYouMean('zzxqwty'))->toBeNull(); // nothing close
});

test('the browse page auto-corrects a typo and shows results for the correction', function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon']);
    $set = Set::factory()->for($line)->create();
    CatalogItem::factory()->for($line)->for($set)->create([
        'name' => 'Greninja', 'item_type' => ItemType::Single,
    ]);

    $this->get('/browse?q=graninj')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('autoCorrectedTo', 'Greninja')
            ->where('didYouMean', null)
            ->where('pagination.total', fn ($t) => $t > 0));
});

test('?exact pins the exact query — no auto-correct, just a suggestion', function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon']);
    $set = Set::factory()->for($line)->create();
    CatalogItem::factory()->for($line)->for($set)->create([
        'name' => 'Greninja', 'item_type' => ItemType::Single,
    ]);

    $this->get('/browse?q=graninj&exact=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', 0)
            ->where('autoCorrectedTo', null)
            ->where('didYouMean', 'Greninja'));
});
