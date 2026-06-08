<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use Database\Seeders\ChaosRisingSeeder;

test('it seeds the chaos rising set with variant-level singles and sealed products', function () {
    $this->seed(ChaosRisingSeeder::class);

    $set = Set::where('slug', 'chaos-rising-en')->first();

    expect($set)->not->toBeNull()
        ->and($set->code)->toBe('CRI')
        ->and($set->language)->toBe('en')
        ->and($set->series)->toBe('Mega Evolution');

    $singles = CatalogItem::where('set_id', $set->id)->where('item_type', ItemType::Single);
    $sealed = CatalogItem::where('set_id', $set->id)->where('item_type', ItemType::Sealed);

    // 122 cards expand to 198 printings; 28 sealed products.
    expect($singles->count())->toBe(198)
        ->and($sealed->count())->toBe(28)
        ->and(CatalogItem::where('set_id', $set->id)->count())->toBe(226);

    // 198 printings collapse to 122 base cards.
    expect($singles->clone()->distinct('base_key')->count('base_key'))->toBe(122);

    // Every single carries the generated language column.
    expect(CatalogItem::where('set_id', $set->id)->whereNull('language')->count())->toBe(0);
});

test('printings of the same card share a base_key and group via variants()', function () {
    $this->seed(ChaosRisingSeeder::class);

    // A common (#001 Weedle) has Normal + Reverse Holofoil printings.
    $weedle = CatalogItem::where('number', '001/086')->get();
    expect($weedle)->toHaveCount(2);
    expect($weedle->pluck('attributes.variant')->sort()->values()->all())
        ->toBe(['normal', 'reverse_holo']);
    expect($weedle->pluck('base_key')->unique())->toHaveCount(1);

    // variants() returns all printings of the card (including itself).
    $one = $weedle->first();
    expect($one->variants()->count())->toBe(2);

    // Each printing is its own value-bearing row (distinct identity_hash).
    expect($weedle->pluck('identity_hash')->unique())->toHaveCount(2);
});

test('re-running the chaos rising seeder is idempotent and reconciles', function () {
    $this->seed(ChaosRisingSeeder::class);
    $this->seed(ChaosRisingSeeder::class);

    expect(Set::where('slug', 'chaos-rising-en')->count())->toBe(1);
    expect(CatalogItem::count())->toBe(226);
});
