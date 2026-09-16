<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg', 'name' => 'Trading Card Games']);
    $this->line = ProductLine::factory()->for($this->vertical)->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration', 'name' => '30th Celebration', 'language' => 'en',
    ]);
});

test('a dry run adds nothing', function () {
    $this->artisan('catalog:add-card', ['set' => '30th-celebration', 'name' => 'Mewtwo ex', 'number' => '151'])
        ->assertSuccessful();

    expect(CatalogItem::where('set_id', $this->set->id)->count())->toBe(0);
});

test('a hand-added card carries its TCGplayer id', function () {
    // Without it the row has nothing tying it to the feed, and the day the feed
    // catches up the importer hashes it differently and lands a second copy —
    // which is how the First Partners promos ended up doubled.
    $this->artisan('catalog:add-card', [
        'set' => '30th-celebration', 'name' => 'Mewtwo ex', 'number' => '151',
        '--rarity' => 'Special Illustration Rare', '--product' => '717603', '--execute' => true,
    ])->assertSuccessful();

    $card = CatalogItem::where('set_id', $this->set->id)->firstOrFail();

    expect($card->name)->toBe('Mewtwo ex')
        ->and($card->number)->toBe('151')
        ->and($card->rarity)->toBe('Special Illustration Rare')
        ->and($card->external_ids['tcgplayer_product_id'])->toBe('717603')
        ->and($card->path())->toBe('/pokemon/30th-celebration/mewtwo-ex-151');
});

test('adding the same card twice does not double it', function () {
    foreach ([1, 2] as $ignored) {
        $this->artisan('catalog:add-card', [
            'set' => '30th-celebration', 'name' => 'Mewtwo ex', 'number' => '151', '--execute' => true,
        ])->assertSuccessful();
    }

    expect(CatalogItem::where('set_id', $this->set->id)->count())->toBe(1);
});

test('an unknown set is refused', function () {
    $this->artisan('catalog:add-card', ['set' => 'no-such-set', 'name' => 'X', 'number' => '1', '--execute' => true])
        ->assertFailed();

    expect(CatalogItem::count())->toBe(0);
});
