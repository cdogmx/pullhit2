<?php

use App\Models\CatalogItem;
use App\Models\Set;
use Inertia\Testing\AssertableInertia as Assert;

function numberedSet(): Set
{
    return Set::factory()->create(['name' => 'Number Sort Probe']);
}

function numbered(Set $set, string $number): CatalogItem
{
    return CatalogItem::factory()->for($set)->create([
        'name' => "Probe {$number}",
        'number' => $number,
    ]);
}

test('browse sorts collector numbers naturally, not lexicographically', function () {
    $set = numberedSet();

    // Deliberately inserted out of order so the assertion can't pass by luck.
    foreach (['100', '2', '11', '1', '20', '3', '10'] as $n) {
        numbered($set, $n);
    }

    $this->get("/browse?set={$set->slug}&sort=number&direction=asc")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(
            collect($page->toArray()['props']['items'])->pluck('number')->all()
        )->toBe(['1', '2', '3', '10', '11', '20', '100']));
});

test('prefixed promo numbers stay in print order and follow the plain numbers', function () {
    $set = numberedSet();

    foreach (['SM164', '5', 'SM99', '50', 'SM12'] as $n) {
        numbered($set, $n);
    }

    // Plain numbers first (as sets print them), then the promo run ordered by
    // its numeric tail — SM12 < SM99 < SM164, not the string order SM12,
    // SM164, SM99.
    $this->get("/browse?set={$set->slug}&sort=number&direction=asc")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(
            collect($page->toArray()['props']['items'])->pluck('number')->all()
        )->toBe(['5', '50', 'SM12', 'SM99', 'SM164']));
});

test('descending number sort is the exact reverse', function () {
    $set = numberedSet();

    foreach (['1', '10', '100', '2'] as $n) {
        numbered($set, $n);
    }

    $this->get("/browse?set={$set->slug}&sort=number&direction=desc")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(
            collect($page->toArray()['props']['items'])->pluck('number')->all()
        )->toBe(['100', '10', '2', '1']));
});
