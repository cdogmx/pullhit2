<?php

use App\Enums\ItemType;
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

test('one card, three colours, three rows', function () {
    $this->artisan('catalog:seed-rgb-secrets', ['--execute' => true])->assertSuccessful();

    $mews = CatalogItem::where('set_id', $this->set->id)->where('number', '30C')->get();

    expect($mews)->toHaveCount(3)
        ->and($mews->pluck('name')->unique()->all())->toBe(['Mew'])
        ->and($mews->map(fn ($m) => $m->attributes['finish'])->sort()->values()->all())
        ->toBe(['blue_rgb', 'green_rgb', 'red_rgb']);
});

test('each colour prices on its own, but they group as one card', function () {
    // The whole reason finish is the right facet: identity_hash separates the
    // three so each carries its own comps, base_key joins them so browse shows
    // them as printings of one card rather than three unrelated Mews.
    $this->artisan('catalog:seed-rgb-secrets', ['--execute' => true]);

    $mews = CatalogItem::where('set_id', $this->set->id)->where('number', '30C')->get();

    expect($mews->pluck('identity_hash')->unique())->toHaveCount(3)
        ->and($mews->pluck('base_key')->unique())->toHaveCount(1);
});

test('the colour stays out of the name', function () {
    // A bracket in the name leaks into the eBay search term, where no listing
    // carries it, and blinds the comp classifier's sibling gate. The display
    // name is derived from the facet instead.
    $this->artisan('catalog:seed-rgb-secrets', ['--execute' => true]);

    $red = CatalogItem::where('number', '30C')->where('attributes->finish', 'red_rgb')->firstOrFail();

    expect($red->name)->toBe('Mew')
        // RGB is an acronym, not a word: never "Red Rgb".
        ->and($red->display_name)->toBe('Mew (Red RGB)');
});

test('a dry run writes nothing', function () {
    $this->artisan('catalog:seed-rgb-secrets')->assertSuccessful();

    expect(CatalogItem::where('number', '30C')->count())->toBe(0);
});

test('re-running adds nothing the second time', function () {
    // All three are called "Mew" and numbered 30C, so the existence check has to
    // match on the finish or the second run doubles them.
    $this->artisan('catalog:seed-rgb-secrets', ['--execute' => true]);
    $this->artisan('catalog:seed-rgb-secrets', ['--execute' => true]);

    expect(CatalogItem::where('number', '30C')->count())->toBe(3);
});

test('an unknown set fails rather than filing them somewhere else', function () {
    $this->artisan('catalog:seed-rgb-secrets', ['--set' => 'no-such-set', '--execute' => true])
        ->assertFailed();

    expect(CatalogItem::where('number', '30C')->count())->toBe(0);
});

test('they are singles, not sealed product', function () {
    $this->artisan('catalog:seed-rgb-secrets', ['--execute' => true]);

    expect(CatalogItem::where('number', '30C')->pluck('item_type')->unique()->all())
        ->toBe([ItemType::Single]);
});
