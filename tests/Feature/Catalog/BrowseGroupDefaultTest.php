<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Whether browse collapses a card's printings into one row is decided by the
 * vertical, because the right answer differs by an order of magnitude.
 *
 * A TCG set has two or three printings of a card and listing them separately is
 * useful. A Topps chrome set has thirty-two, so one row per printing turns a
 * 200-card set into 6,400 near-identical rows — the state that made the Disney
 * Chrome import look broken when it was not.
 */
function printings(Vertical $vertical, Set $set, string $name, array $treatments): void
{
    $create = app(CreateCatalogItem::class);
    $line = ProductLine::find($set->product_line_id);

    foreach ($treatments as $facets) {
        $create(
            vertical: $vertical, productLine: $line, set: $set,
            itemType: ItemType::Single, name: $name, number: '50',
            attributes: ['language' => 'en', ...$facets],
        );
    }
}

function collectiblesSet(): Set
{
    $vertical = Vertical::factory()->create(['slug' => 'collectibles', 'name' => 'Collectibles']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'topps']);
    $set = Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'disney-chrome', 'language' => 'en']);

    printings($vertical, $set, 'Mickey Mouse', [
        [],
        ['parallel' => 'Refractor'],
        ['parallel' => 'Gold Refractor'],
    ]);

    return $set;
}

function tcgSet(): Set
{
    $vertical = Vertical::factory()->create(['slug' => 'tcg', 'name' => 'TCG']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'pokemon']);
    $set = Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'celebrations', 'language' => 'en']);

    printings($vertical, $set, 'Pikachu', [
        ['variant' => 'normal'],
        ['variant' => 'reverse_holo'],
    ]);

    return $set;
}

test('a collectibles set collapses its printings to one row per card', function () {
    collectiblesSet();

    $this->get('/browse?set=disney-chrome')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('groupDefault', true)
            ->where('filters.group', true)
            // One Mickey, not three.
            ->has('items', 1)
            ->where('items.0.variants_count', 3));
});

test('a tcg set still lists every printing', function () {
    tcgSet();

    $this->get('/browse?set=celebrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('groupDefault', false)
            ->where('filters.group', false)
            ->has('items', 2));
});

test('an explicit group=0 beats the collectibles default', function () {
    collectiblesSet();

    // The toggle has to be able to win, which is why the filter is tri-state:
    // an absent group means "not asked", not "off".
    $this->get('/browse?set=disney-chrome&group=0')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.group', false)
            ->has('items', 3));
});

test('an explicit group=1 groups a tcg set', function () {
    tcgSet();

    $this->get('/browse?set=celebrations&group=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.group', true)
            ->has('items', 1));
});
