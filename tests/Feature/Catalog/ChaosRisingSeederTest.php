<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use Database\Seeders\ChaosRisingSeeder;

test('it seeds the chaos rising set with its full english card list', function () {
    $this->seed(ChaosRisingSeeder::class);

    $set = Set::where('slug', 'chaos-rising-en')->first();

    expect($set)->not->toBeNull()
        ->and($set->code)->toBe('CRI')
        ->and($set->language)->toBe('en')
        ->and($set->series)->toBe('Mega Evolution');

    // The fixture is the full 122-card set (incl. secret rares beyond the printed 86).
    expect($set->catalogItems()->count())->toBe(122);

    // Every item is an English single and carries a populated generated language column.
    expect(CatalogItem::where('set_id', $set->id)->where('item_type', ItemType::Single)->count())->toBe(122);
    expect(CatalogItem::where('set_id', $set->id)->whereNull('language')->count())->toBe(0);

    $chase = CatalogItem::where('set_id', $set->id)->where('number', '122')->first();
    expect($chase->name)->toBe('Mega Greninja ex')
        ->and($chase->attributes['rarity'])->toBe('Mega Hyper Rare');
});

test('re-running the chaos rising seeder is idempotent', function () {
    $this->seed(ChaosRisingSeeder::class);
    $this->seed(ChaosRisingSeeder::class);

    expect(Set::where('slug', 'chaos-rising-en')->count())->toBe(1);
    expect(CatalogItem::count())->toBe(122);
});
