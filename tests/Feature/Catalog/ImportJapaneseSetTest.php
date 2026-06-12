<?php

use App\Actions\Catalog\ImportJapaneseSet;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');

    Http::fake([
        'tcgcsv.com/tcgplayer/85/groups' => Http::response(['results' => [
            ['groupId' => 24600, 'name' => 'M3: Nihil Zero', 'publishedOn' => '2026-02-20T00:00:00', 'categoryId' => 85],
        ]]),
        'tcgcsv.com/tcgplayer/85/24600/products' => Http::response(['results' => [
            [
                'productId' => 674320, 'name' => 'Spinarak', 'cleanName' => 'Spinarak',
                'imageUrl' => 'https://tcgplayer-cdn.tcgplayer.com/product/674320_200w.jpg',
                'extendedData' => [
                    ['name' => 'Rarity', 'value' => 'Common'],
                    ['name' => 'Number', 'value' => '001/080'],
                ],
            ],
        ]]),
        'tcgcsv.com/tcgplayer/85/24600/prices' => Http::response(['results' => [
            ['productId' => 674320, 'subTypeName' => 'Normal', 'marketPrice' => 0.10, 'lowPrice' => 0.08],
            ['productId' => 674320, 'subTypeName' => 'Holofoil', 'marketPrice' => 2.00, 'lowPrice' => 1.00],
        ]]),
        'tcgplayer-cdn.tcgplayer.com/*' => Http::response('FAKE-PNG-BYTES', 200),
    ]);
});

test('it imports a Japanese set with ja language, clean name, and slug', function () {
    $r = app(ImportJapaneseSet::class)(24600);

    expect($r)->toMatchArray(['set' => 'Nihil Zero', 'items' => 2, 'valued' => 2]);

    $set = Set::where('slug', 'nihil-zero-ja')->first();
    expect($set)->not->toBeNull()
        ->and($set->language)->toBe('ja')
        ->and($set->name)->toBe('Nihil Zero')
        ->and($set->code)->toBe('M3');

    $items = CatalogItem::where('set_id', $set->id)->get();
    expect($items)->toHaveCount(2) // normal + holo
        ->and($items->pluck('number')->unique()->values()->all())->toBe(['001'])
        ->and($items->first()->language)->toBe('ja')
        ->and($items->first()->primary_image_path)->not->toBeNull();

    expect(MarketValue::whereIn('catalog_item_id', $items->pluck('id'))->where('is_estimated', true)->exists())
        ->toBeTrue();
});

test('it is idempotent on re-run (no duplicate set or items)', function () {
    app(ImportJapaneseSet::class)(24600);
    app(ImportJapaneseSet::class)(24600);

    expect(Set::where('slug', 'nihil-zero-ja')->count())->toBe(1);
    $set = Set::where('slug', 'nihil-zero-ja')->first();
    expect(CatalogItem::where('set_id', $set->id)->count())->toBe(2);
});
