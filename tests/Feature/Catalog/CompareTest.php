<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon']);
    $this->set = Set::factory()->for($line)->create(['slug' => 'base']);
});

function compareCard(Set $set, string $name): CatalogItem
{
    return CatalogItem::factory()->create([
        'product_line_id' => $set->product_line_id,
        'set_id' => $set->id,
        'name' => $name,
        // Normal printing → display_name has no variant qualifier appended.
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
}

test('the compare page renders selected cards in the requested order', function () {
    $a = compareCard($this->set, 'Alpha');
    $b = compareCard($this->set, 'Bravo');
    $c = compareCard($this->set, 'Charlie');

    $this->get("/compare?ids={$c->id},{$a->id},{$b->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/compare')
            ->has('items', 3)
            // Order mirrors the ids query, and each item ships a history series.
            ->where('items.0.name', 'Charlie')
            ->where('items.1.name', 'Alpha')
            ->where('items.2.name', 'Bravo')
            ->has('items.0.series.points')
            ->where('maxItems', 5));
});

test('it caps the comparison at five cards and ignores duplicates/unknowns', function () {
    $cards = collect(range(1, 7))->map(fn ($n) => compareCard($this->set, "Card {$n}"));
    // 7 ids + a duplicate + an unknown id.
    $ids = $cards->pluck('id')->push($cards[0]->id)->push(999999)->implode(',');

    $this->get("/compare?ids={$ids}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('items', 5));
});

test('the bare compare page renders with no items', function () {
    $this->get('/compare')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/compare')
            ->has('items', 0));
});
