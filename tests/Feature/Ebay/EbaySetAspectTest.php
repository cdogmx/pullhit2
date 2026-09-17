<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Ebay\EbaySoldSource;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->source = app(EbaySoldSource::class);

    $this->card = fn (Set $set, array $attributes = []) => CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $set->id,
        'name' => 'Sylveon ex', 'number' => '71', 'item_type' => ItemType::Single,
        'attributes' => array_merge(['language' => 'en', 'variant' => 'holo'], $attributes),
    ]);
});

test('a mapped set is pinned by eBay\'s own name for it', function () {
    // We call it "30th Celebration"; eBay calls it "30th Anniversary Edition",
    // and no rule derives one from the other.
    $set = Set::factory()->for($this->line)->create([
        'name' => '30th Celebration', 'language' => 'en',
        'ebay_set' => '30th Anniversary Edition',
    ]);

    expect($this->source->soldSearchUrl(($this->card)($set)))
        ->toContain(urlencode('30th Anniversary Edition'));
});

test('an unmapped set searches on keywords alone', function () {
    // Most sets have no mapping, and must be no worse off for it.
    $set = Set::factory()->for($this->line)->create(['name' => 'Paldean Fates', 'ebay_set' => null]);

    expect($this->source->soldSearchUrl(($this->card)($set)))->not->toContain('Set=');
});

test('a single searches inside the individual-cards category', function () {
    $set = Set::factory()->for($this->line)->create(['name' => 'Paldean Fates']);

    expect($this->source->soldSearchUrl(($this->card)($set)))->toContain('_dcat=183454');
});

test('a sealed product is not filed as an individual card', function () {
    // It genuinely is not one, and belongs in whatever category eBay files
    // boxes under.
    $set = Set::factory()->for($this->line)->create(['name' => 'Paldean Fates', 'ebay_set' => 'Paldean Fates']);
    $box = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $set->id,
        'name' => 'Elite Trainer Box', 'item_type' => ItemType::Sealed,
        'attributes' => ['language' => 'en'],
    ]);

    $url = $this->source->soldSearchUrl($box);

    expect($url)->not->toContain('_dcat=183454')->not->toContain('Set=');
});

test('the mapping is never guessed', function () {
    // eBay's distribution for any 30th Celebration card is led by
    // "Celebrations", a different set. Recording that would pin every comp to
    // the wrong set, so the command reports and a person decides.
    $set = Set::factory()->for($this->line)->create(['slug' => 'x-set', 'name' => 'X Set']);
    CatalogItem::factory()->count(3)->create([
        'product_line_id' => $this->line->id, 'set_id' => $set->id,
        'item_type' => ItemType::Single, 'attributes' => ['language' => 'en'],
    ]);

    // Without --execute nothing is written, even when told the answer.
    $this->artisan('ebay:learn-set-aspect', ['--set' => 'x-set', '--value' => 'Whatever'])
        ->assertSuccessful();

    expect($set->fresh()->ebay_set)->toBeNull();

    $this->artisan('ebay:learn-set-aspect', ['--set' => 'x-set', '--value' => 'Whatever', '--execute' => true])
        ->assertSuccessful();

    expect($set->fresh()->ebay_set)->toBe('Whatever');
});

test('a value needs a set to belong to', function () {
    $this->artisan('ebay:learn-set-aspect', ['--value' => 'Orphan', '--execute' => true])
        ->assertFailed();
});
