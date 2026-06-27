<?php

use App\Actions\Valuation\SweepSealedComps;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function sealedWithValue(string $name, int $cents, ?CarbonImmutable $refreshed = null): CatalogItem
{
    $item = CatalogItem::factory()->create([
        'name' => $name,
        'item_type' => ItemType::Sealed,
        'attributes' => ['sealed_type' => 'booster_box', 'language' => 'en'],
        'ebay_refreshed_at' => $refreshed,
    ]);
    MarketValue::factory()->for($item)->create(['state_key' => 'SEALED', 'median' => $cents]);

    return $item;
}

test('a dry run selects in-band, stale sealed products', function () {
    sealedWithValue('Cheap Pack', 500);                 // below the $20 floor
    sealedWithValue('Mid Box', 8000);                   // due, $80 — in band
    sealedWithValue('Top Box', 30000);                  // due, $300 — in band
    sealedWithValue('Fresh Box', 40000, now());         // in band but refreshed just now
    sealedWithValue('Phantom Box', 6000000);            // $60k data-error outlier — above ceiling
    sealedWithValue('Brilliant Booster Box Case', 30000); // a Case — excluded by name

    $r = app(SweepSealedComps::class)(limit: 40, minValueCents: 2000, maxValueCents: 500000, maxAgeHours: 168, dryRun: true);

    // Mid + Top only; Cheap (floor), Fresh (recent), Phantom (ceiling) and the Case (name) excluded.
    expect($r['due'])->toBe(2)
        ->and($r['processed'])->toBe(2);
});

test('it stops at the daily Oxylabs cap', function () {
    config(['valuation.ebay.daily_cap' => 1]);
    Cache::put('ebay:daily:'.now()->toDateString(), 1, now()->endOfDay()); // already at cap

    sealedWithValue('Top Box', 30000);
    sealedWithValue('Mid Box', 8000);

    $r = app(SweepSealedComps::class)(limit: 40, minValueCents: 2000, maxValueCents: 500000, maxAgeHours: 168, dryRun: false);

    expect($r['processed'])->toBe(0)
        ->and($r['capped'])->toBeTrue();
});
