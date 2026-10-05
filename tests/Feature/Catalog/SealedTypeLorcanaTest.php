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

/**
 * Lorcana names two major sealed SKUs in words the type matcher did not know.
 *
 * An Illumineer's Trove says neither box nor pack nor deck, and a "2-Player
 * Starter Set" only ever landed when TCGplayer also wrote "Display" — which is
 * why one Trove and four starter sets exist across fifteen Lorcana sets instead
 * of one of each per set. Four of Hyperia City's nine products were dropped.
 *
 * Its own file rather than a case in ImportSealedProductsTest: that file stubs
 * the products endpoint in beforeEach, and Http::fake appends with first match
 * winning, so a second stub there never applies.
 */
beforeEach(function () {
    Storage::fake('s3');
    config(['services.tcgcsv.base_url' => 'https://tcgcsv.com']);

    Http::fake([
        '*/products' => Http::response(['results' => [
            ['productId' => 10, 'name' => "Disney Lorcana: Hyperia City Illumineer's Trove", 'imageUrl' => null, 'extendedData' => []],
            ['productId' => 11, 'name' => "Disney Lorcana: Hyperia City Illumineer's Trove Case", 'imageUrl' => null, 'extendedData' => []],
            ['productId' => 12, 'name' => 'Disney Lorcana: Hyperia City 2-Player Starter Set', 'imageUrl' => null, 'extendedData' => []],
            ['productId' => 13, 'name' => 'Disney Lorcana: Hyperia City 2-Player Starter Set Case', 'imageUrl' => null, 'extendedData' => []],
            ['productId' => 14, 'name' => 'Disney Lorcana: Hyperia City Collection Starter Set', 'imageUrl' => null, 'extendedData' => []],
            ['productId' => 15, 'name' => 'Disney Lorcana: Hyperia City Online Code Card', 'imageUrl' => null, 'extendedData' => []],
        ]], 200),
        '*/prices' => Http::response(['results' => []], 200),
    ]);

    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'lorcana']);
    $this->set = Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'hyperia-city', 'language' => 'en']);
});

test('a Trove and a 2-player starter set are imported as sealed product', function () {
    $result = app(ImportSealedProducts::class)($this->set, 24740, TcgcsvClient::LORCANA);

    $type = fn (string $like) => CatalogItem::where('name', 'like', "%{$like}%")
        ->first()?->attributes['sealed_type'] ?? null;

    // Five sealed; the code card is still not product.
    expect($result['created'])->toBe(5)
        ->and($result['skipped'])->toContain('Disney Lorcana: Hyperia City Online Code Card')
        ->and($type("Illumineer's Trove"))->toBe('other')
        ->and($type('2-Player Starter Set'))->toBe('other');
});

test('a Case comes along with the thing it is a case of', function () {
    app(ImportSealedProducts::class)($this->set, 24740, TcgcsvClient::LORCANA);

    // 'trove' and 'starter set' each catch their own Case, which is why bare
    // 'case' is not a signal: it would pull in deck boxes and card cases, and
    // an accessory is not sealed cards.
    expect(CatalogItem::where('name', 'like', '%Trove Case%')->first()?->attributes['sealed_type'])->toBe('other')
        ->and(CatalogItem::where('name', 'like', '%Starter Set Case%')->first()?->attributes['sealed_type'])->toBe('other')
        ->and(CatalogItem::where('item_type', ItemType::Sealed)->count())->toBe(5);
});

test('a Collection Starter Set stays a collection', function () {
    app(ImportSealedProducts::class)($this->set, 24740, TcgcsvClient::LORCANA);

    // sealed_type is identity-defining, so the new arms sit AFTER the collection
    // one: reclassifying this would rehash the six rows that already exist.
    expect(CatalogItem::where('name', 'like', '%Collection Starter Set%')->first()->attributes['sealed_type'])
        ->toBe('collection');
});
