<?php

use App\Actions\Catalog\ImportSealedProducts;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function sealedSet(): Set
{
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'pokemon']);

    return Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'chaos-rising', 'language' => 'en']);
}

beforeEach(function () {
    Storage::fake('s3');
    config(['services.tcgcsv.base_url' => 'https://tcgcsv.com']);

    Http::fake([
        '*/products' => Http::response(['results' => [
            // A single (has a Number) — must be skipped.
            ['productId' => 1, 'name' => 'Pikachu', 'imageUrl' => 'https://img/1.jpg',
                'extendedData' => [['name' => 'Number', 'value' => '58/162']]],
            // Sealed products (no Number).
            ['productId' => 2, 'name' => 'Chaos Rising Booster Box', 'imageUrl' => 'https://img/2.jpg', 'extendedData' => []],
            ['productId' => 3, 'name' => 'Chaos Rising Elite Trainer Box', 'imageUrl' => 'https://img/3.jpg', 'extendedData' => []],
            ['productId' => 4, 'name' => 'Chaos Rising Booster Pack', 'imageUrl' => 'https://img/4.jpg', 'extendedData' => []],
            // A no-number, non-sealed product — must be skipped.
            ['productId' => 5, 'name' => 'Chaos Rising Online Code Card', 'imageUrl' => null, 'extendedData' => []],
        ]], 200),
        '*/prices' => Http::response(['results' => [
            ['productId' => 2, 'marketPrice' => 119.99, 'subTypeName' => 'Normal'],
            ['productId' => 3, 'marketPrice' => 49.50, 'subTypeName' => 'Normal'],
        ]], 200),
        'img/*' => Http::response('IMG', 200, ['Content-Type' => 'image/jpeg']),
    ]);
});

test('it imports only sealed products, with type/image/price', function () {
    $set = sealedSet();

    $r = app(ImportSealedProducts::class)($set, groupId: 24655, category: TcgcsvClient::POKEMON);

    expect($r['created'])->toBe(3) // box, ETB, pack — not the single, not the code card
        ->and($r['images'])->toBe(3)
        ->and($r['valued'])->toBe(2) // box + ETB have prices
        ->and($r['skipped'])->toContain('Chaos Rising Online Code Card');

    $box = CatalogItem::where('name', 'Chaos Rising Booster Box')->first();
    expect($box)->not->toBeNull()
        ->and($box->item_type)->toBe(ItemType::Sealed)
        ->and($box->attributes['sealed_type'])->toBe('booster_box')
        ->and($box->external_ids['tcgplayer_product_id'])->toBe('2')
        ->and($box->primary_image_path)->not->toBeNull();

    expect(CatalogItem::where('name', 'Chaos Rising Elite Trainer Box')->first()->attributes['sealed_type'])->toBe('elite_trainer_box')
        ->and(CatalogItem::where('name', 'Pikachu')->exists())->toBeFalse();
});

test('a dry run writes nothing', function () {
    $set = sealedSet();

    $r = app(ImportSealedProducts::class)($set, groupId: 24655, dryRun: true);

    expect($r['created'])->toBe(3)
        ->and(CatalogItem::count())->toBe(0);
});

test('a second run is idempotent', function () {
    $set = sealedSet();
    app(ImportSealedProducts::class)($set, 24655, withImages: false);
    app(ImportSealedProducts::class)($set, 24655, withImages: false);

    expect(CatalogItem::where('name', 'Chaos Rising Booster Box')->count())->toBe(1);
});
