<?php

use App\Actions\Catalog\ImportPricechartingGame;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Support\Pricecharting\GameCatalogParser;
use App\Support\Pricecharting\GameImportSpec;

$csv = implode("\n", [
    'id,console-name,product-name,loose-price,new-price,graded-price,box-only-price,manual-only-price,bgs-10-price,genre,tcg-id,release-date',
    '1,One Piece Paramount War,Monkey D. Luffy OP02-001,$5.00,,$40.00,,$120.00,,One Piece Cards,12345,2023-09-22',
    '2,One Piece Paramount War,Monkey D. Luffy OP02-001 [Alternate Art],$50.00,,,,,,One Piece Cards,,2023-09-22',
    '3,One Piece Paramount War,2023 Sealed Booster Box,$100.00,,,,,,Sealed Product,,2023-09-22',
]);

test('the parser extracts name, number, and parallel tags from One Piece product names', function () use ($csv) {
    $rows = iterator_to_array((new GameCatalogParser(GameImportSpec::forGame('one-piece')))($csv));

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['name'])->toBe('Monkey D. Luffy')
        ->and($rows[0]['number'])->toBe('OP02-001')
        ->and($rows[0]['finish'])->toBeNull()
        ->and($rows[1]['finish'])->toBe('alternate_art') // the [Alternate Art] parallel
        ->and($rows[2]['is_sealed'])->toBeTrue()
        ->and($rows[0]['prices']['ungraded'])->toBe(500);
});

test('it builds the catalog: product line, set, distinct printings, seeded value', function () use ($csv) {
    $stats = app(ImportPricechartingGame::class)(
        GameImportSpec::forGame('one-piece'), $csv, withImages: false, withPrices: true,
    );

    // Two singles created (the sealed booster box is skipped).
    expect($stats['items'])->toBe(2)
        ->and($stats['sets'])->toBe(1);

    expect(ProductLine::where('slug', 'one-piece')->exists())->toBeTrue();

    $base = CatalogItem::where('name', 'Monkey D. Luffy')->whereJsonDoesntContain('attributes->finish', 'alternate_art')->first();
    expect($base->number)->toBe('OP02-001')
        ->and($base->set->name)->toBe('Paramount War')
        ->and($base->set->code)->toBe('OP02')
        ->and($base->defaultMarketValue)->not->toBeNull()
        ->and($base->defaultMarketValue->is_estimated)->toBeTrue();

    // The alternate-art parallel is a separate catalog item (variant-defining finish).
    expect(CatalogItem::where('name', 'Monkey D. Luffy')->count())->toBe(2);
});

test('it is idempotent — re-running does not duplicate', function () use ($csv) {
    $spec = GameImportSpec::forGame('one-piece');
    app(ImportPricechartingGame::class)($spec, $csv, withImages: false, withPrices: false);
    app(ImportPricechartingGame::class)($spec, $csv, withImages: false, withPrices: false);

    expect(CatalogItem::where('name', 'Monkey D. Luffy')->count())->toBe(2);
});
