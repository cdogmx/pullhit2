<?php

use App\Actions\Catalog\ImportAllSealed;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    config(['services.tcgcsv.base_url' => 'https://tcgcsv.com']);

    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'pokemon']);

    Http::fake([
        '*/tcgplayer/3/groups' => Http::response(['results' => [
            ['groupId' => 24655, 'name' => 'ME04: Chaos Rising'],
            ['groupId' => 24688, 'name' => 'ME05: Pitch Black'],
        ]], 200),
        '*/24655/products' => Http::response(['results' => [
            ['productId' => 2, 'name' => 'Chaos Rising Booster Box', 'imageUrl' => 'https://img/2.jpg', 'extendedData' => []],
        ]], 200),
        '*/24655/prices' => Http::response(['results' => [['productId' => 2, 'marketPrice' => 100.0]]], 200),
        'img/*' => Http::response('IMG', 200, ['Content-Type' => 'image/jpeg']),
    ]);
});

test('it matches a set to its TCGplayer group (handling the set-code prefix) and imports sealed', function () {
    Set::factory()->create(['product_line_id' => $this->line->id, 'name' => 'Chaos Rising', 'slug' => 'chaos-rising', 'language' => 'en']);

    $r = app(ImportAllSealed::class)(
        [['line' => 'pokemon', 'category' => 3, 'languages' => ['en']]],
        dryRun: false, withImages: true, log: fn () => null,
    );

    expect($r['matched'])->toBe(1)
        ->and($r['sealed'])->toBe(1)
        ->and($r['unmatched'])->toBe([])
        ->and(CatalogItem::where('name', 'Chaos Rising Booster Box')->exists())->toBeTrue();
});

test('a set with no matching group is reported, not guessed', function () {
    Set::factory()->create(['product_line_id' => $this->line->id, 'name' => 'Totally Unknown Set', 'slug' => 'unknown', 'language' => 'en']);

    $r = app(ImportAllSealed::class)(
        [['line' => 'pokemon', 'category' => 3, 'languages' => ['en']]],
        dryRun: false, withImages: false, log: fn () => null,
    );

    expect($r['matched'])->toBe(0)
        ->and($r['unmatched'])->toContain('pokemon/Totally Unknown Set')
        ->and(CatalogItem::count())->toBe(0);
});

test('a dry run writes nothing', function () {
    Set::factory()->create(['product_line_id' => $this->line->id, 'name' => 'Chaos Rising', 'slug' => 'chaos-rising', 'language' => 'en']);

    $r = app(ImportAllSealed::class)(
        [['line' => 'pokemon', 'category' => 3, 'languages' => ['en']]],
        dryRun: true, withImages: false, log: fn () => null,
    );

    expect($r['sealed'])->toBe(1)
        ->and(CatalogItem::count())->toBe(0);
});
