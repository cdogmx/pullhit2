<?php

use App\Actions\Valuation\MaybeRefreshEbay;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;

/**
 * What a provisional row is kept out of.
 *
 * This is the half of the feature that matters. A row from an unreviewed vision
 * read carries two specific hazards: a name that will not match the official
 * import's, making a permanent duplicate, and a number that may be misread —
 * and comps match on set and number, so a wrong one collects another card's
 * sales. That is how a $4 card came to be priced at $53.
 *
 * Each exclusion gets a test because a silent regression here is invisible: the
 * row simply starts appearing, and the damage shows up weeks later as a price
 * nobody can explain.
 */
beforeEach(function () {
    $vertical = Vertical::firstOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'pokemon']);
    $this->set = Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'base', 'name' => 'Base']);

    $this->confirmed = CatalogItem::factory()->create([
        'vertical_id' => $vertical->id, 'product_line_id' => $line->id, 'set_id' => $this->set->id,
        'name' => 'Pikachu', 'number' => '58',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);

    $this->provisional = CatalogItem::factory()->create([
        'vertical_id' => $vertical->id, 'product_line_id' => $line->id, 'set_id' => $this->set->id,
        'name' => 'Pikachu Scanned', 'number' => '59',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
        'is_provisional' => true,
    ]);
});

test('browse and search never show it', function () {
    $results = app(\App\Actions\Catalog\SearchCatalog::class)->__invoke(['q' => 'Pikachu']);

    $ids = collect($results->items())->pluck('id');

    expect($ids)->toContain($this->confirmed->id)
        ->not->toContain($this->provisional->id);
});

test('the sitemap never lists it', function () {
    // A URL indexed before review is worse than one never submitted: the name
    // can change on confirmation, and the address moves with it.
    $this->get('/sitemap-cards-1.xml')
        ->assertOk()
        ->assertSee($this->confirmed->slug)
        ->assertDontSee($this->provisional->slug);
});

test('the comp queue never picks it up', function () {
    $this->artisan('ebay:enqueue-sold', ['--set' => 'base'])->assertSuccessful();

    expect(\App\Models\EbayScrapeJob::where('catalog_item_id', $this->provisional->id)->exists())->toBeFalse()
        ->and(\App\Models\EbayScrapeJob::where('catalog_item_id', $this->confirmed->id)->exists())->toBeTrue();
});

test('viewing it never spends a paid refresh', function () {
    // Reachable from its owner's collection even though browse hides it, so the
    // guard has to live in the refresh rather than the controller.
    expect(app(MaybeRefreshEbay::class)($this->provisional))->toBeFalse();
});

test('a sweep will not attach a sale to it', function () {
    // The resolver matches a listing title to a card by number. An unconfirmed
    // number came from a vision read, so a match here would put a real sale on a
    // card that may not exist as described.
    $resolved = app(\App\Support\Ebay\EbayTitleResolver::class)
        ->resolve('Pikachu Scanned 59 Base Set Holo', 'en', 0.5, 'pokemon');

    expect($resolved['best_id'] ?? null)->not->toBe($this->provisional->id);
});

test('its owner still sees it in their own collection', function () {
    // The whole point: the person holding the card can log it now. Hiding it
    // from them would make the row pointless.
    $user = \App\Models\User::factory()->create();
    $collection = $user->collections()->create(['name' => 'Main', 'slug' => 'main-'.$user->id]);
    $collection->items()->create([
        'user_id' => $user->id,
        'catalog_item_id' => $this->provisional->id, 'quantity' => 1, 'condition' => 'NM',
    ]);

    expect($collection->items()->where('catalog_item_id', $this->provisional->id)->exists())->toBeTrue();
});
