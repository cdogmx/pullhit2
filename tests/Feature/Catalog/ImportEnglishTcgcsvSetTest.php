<?php

use App\Actions\Catalog\ImportEnglishTcgcsvSet;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');

    Http::fake([
        'tcgcsv.com/tcgplayer/3/groups' => Http::response(['results' => [
            ['groupId' => 24688, 'name' => 'ME05: Pitch Black', 'publishedOn' => '2026-07-17T00:00:00', 'categoryId' => 3],
        ]]),
        'tcgcsv.com/tcgplayer/3/24688/products' => Http::response(['results' => [
            // TCGCSV appends the number to some EN names — must be cleaned off.
            [
                'productId' => 700001, 'name' => 'Fomantis - 003/084', 'cleanName' => 'Fomantis',
                'imageUrl' => 'https://tcgplayer-cdn.tcgplayer.com/product/700001_200w.jpg',
                'extendedData' => [
                    ['name' => 'Rarity', 'value' => 'Common'],
                    ['name' => 'Number', 'value' => '003/084'],
                ],
            ],
            // A sealed product (no collector Number) is skipped here.
            [
                'productId' => 700099, 'name' => 'Pitch Black Booster Box',
                'extendedData' => [],
            ],
        ]]),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response(['results' => [
            ['productId' => 700001, 'subTypeName' => 'Normal', 'marketPrice' => 0.44, 'lowPrice' => 0.30],
            ['productId' => 700001, 'subTypeName' => 'Reverse Holofoil', 'marketPrice' => 0.59, 'lowPrice' => 0.40],
        ]]),
        'tcgplayer-cdn.tcgplayer.com/*' => Http::response('FAKE-PNG-BYTES', 200),
    ]);
});

test('it imports an English set with en language, clean names, zero-stripped numbers', function () {
    $r = app(ImportEnglishTcgcsvSet::class)(24688);

    expect($r)->toMatchArray(['set' => 'Pitch Black', 'items' => 2, 'valued' => 2]);

    $set = Set::where('slug', 'pitch-black')->first();
    expect($set)->not->toBeNull()
        ->and($set->language)->toBe('en')
        ->and($set->name)->toBe('Pitch Black')
        ->and($set->code)->toBe('ME05')
        ->and($set->released_at?->toDateString())->toBe('2026-07-17');

    $items = CatalogItem::where('set_id', $set->id)->get();
    expect($items)->toHaveCount(2) // normal + reverse_holo of the one card (sealed skipped)
        ->and($items->pluck('name')->unique()->values()->all())->toBe(['Fomantis']) // " - 003/084" stripped
        ->and($items->pluck('number')->unique()->values()->all())->toBe(['3'])      // "003" → "3"
        ->and($items->first()->language)->toBe('en')
        ->and($items->first()->primary_image_path)->not->toBeNull();

    expect(MarketValue::whereIn('catalog_item_id', $items->pluck('id'))->where('is_estimated', true)->exists())
        ->toBeTrue();
});

test('it is idempotent on re-run (no duplicate set or items)', function () {
    app(ImportEnglishTcgcsvSet::class)(24688);
    app(ImportEnglishTcgcsvSet::class)(24688);

    $set = Set::where('slug', 'pitch-black')->first();
    expect(Set::where('slug', 'pitch-black')->count())->toBe(1)
        ->and(CatalogItem::where('set_id', $set->id)->count())->toBe(2);
});
