<?php

use App\Models\CatalogItem;
use App\Models\SaleObservation;

test('last sold is the most recently ingested sale on the newest day, not an arbitrary one', function () {
    $item = CatalogItem::factory()->create();

    // Three NM sales all dated the SAME day (eBay sweeps store observed_at as
    // date-only). Insert them out of order so the row order can't pass by luck.
    $day = now()->startOfDay();
    SaleObservation::factory()->for($item)->create([
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 39999, 'observed_at' => $day, 'created_at' => $day->copy()->setTime(13, 10),
        'is_synthetic' => false, 'is_outlier' => false,
    ]);
    SaleObservation::factory()->for($item)->create([
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 32500, 'observed_at' => $day, 'created_at' => $day->copy()->setTime(15, 0),
        'is_synthetic' => false, 'is_outlier' => false,
    ]);
    $latest = SaleObservation::factory()->for($item)->create([
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 30000, 'observed_at' => $day, 'created_at' => $day->copy()->setTime(19, 30),
        'is_synthetic' => false, 'is_outlier' => false,
    ]);

    $last = $item->lastSalesByState();

    // The genuinely latest ingest (19:30, $300) — not the early high sale ($399.99).
    expect($last['NM']['price'])->toBe(30000)
        ->and($last['NM']['price'])->toBe((int) $latest->price);
});

test('last sold ignores synthetic and outlier observations', function () {
    $item = CatalogItem::factory()->create();
    $day = now()->startOfDay();

    // A synthetic placeholder and a flagged outlier are both newer than the real
    // sale, but must not be reported as "last sold".
    SaleObservation::factory()->for($item)->create([
        'condition' => 'NM', 'price' => 99999, 'observed_at' => $day,
        'created_at' => $day->copy()->setTime(23, 0), 'is_synthetic' => true, 'is_outlier' => false,
    ]);
    SaleObservation::factory()->for($item)->create([
        'condition' => 'NM', 'price' => 88888, 'observed_at' => $day,
        'created_at' => $day->copy()->setTime(22, 0), 'is_synthetic' => false, 'is_outlier' => true,
    ]);
    SaleObservation::factory()->for($item)->create([
        'condition' => 'NM', 'price' => 31000, 'observed_at' => $day,
        'created_at' => $day->copy()->setTime(12, 0), 'is_synthetic' => false, 'is_outlier' => false,
    ]);

    expect($item->lastSalesByState()['NM']['price'])->toBe(31000);
});
