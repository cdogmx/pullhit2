<?php

use App\Actions\Catalog\ImportPokemonSet;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\Set;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    Storage::fake('s3');

    Http::fake([
        'api.pokemontcg.io/v2/sets/*' => Http::response(['data' => [
            'id' => 'sv8', 'name' => 'Surging Sparks', 'series' => 'Scarlet & Violet',
            'releaseDate' => '2024/11/08', 'ptcgoCode' => 'SSP',
        ]]),
        'api.pokemontcg.io/v2/cards*' => Http::response([
            'data' => [[
                'id' => 'sv8-1', 'name' => 'Pikachu', 'number' => '1', 'rarity' => 'Common',
                'artist' => 'Ken Sugimori', 'hp' => '60', 'types' => ['Lightning'],
                'images' => ['large' => 'https://images.pokemontcg.io/sv8/1_hires.png'],
                'tcgplayer' => ['prices' => [
                    'normal' => ['market' => 0.50, 'low' => 0.10, 'high' => 1.00],
                    'reverseHolofoil' => ['market' => 2.00, 'low' => 1.00, 'high' => 5.00],
                ]],
            ]],
            'page' => 1, 'pageSize' => 250, 'count' => 1, 'totalCount' => 1,
        ]),
        'images.pokemontcg.io/*' => Http::response('FAKE-PNG-BYTES', 200),
    ]);
});

test('it imports a set: catalog items, images, and estimated values', function () {
    $r = app(ImportPokemonSet::class)('sv8');

    expect($r)->toMatchArray(['set' => 'Surging Sparks', 'items' => 2, 'valued' => 2, 'images' => 1]);

    $set = Set::where('slug', 'surging-sparks')->first();
    expect($set)->not->toBeNull()
        ->and($set->code)->toBe('SSP')
        ->and($set->series)->toBe('Scarlet & Violet');

    $items = CatalogItem::where('set_id', $set->id)->get();
    expect($items)->toHaveCount(2) // normal + reverse_holo
        ->and($items->first()->primary_image_path)->not->toBeNull();

    Storage::disk('s3')->assertExists('phb/pokemon/sv8/sv8-1.png');

    expect(MarketValue::whereIn('catalog_item_id', $items->pluck('id'))->where('is_estimated', true)->exists())
        ->toBeTrue();
});

test('it is idempotent on re-run (no duplicates, no re-download)', function () {
    app(ImportPokemonSet::class)('sv8');
    app(ImportPokemonSet::class)('sv8');

    $set = Set::where('slug', 'surging-sparks')->first();
    expect(CatalogItem::where('set_id', $set->id)->count())->toBe(2);
});

test('--no-prices and --no-images skip those steps', function () {
    $r = app(ImportPokemonSet::class)('sv8', withPrices: false, withImages: false);

    expect($r['valued'])->toBe(0)->and($r['images'])->toBe(0);

    $set = Set::where('slug', 'surging-sparks')->first();
    $ids = CatalogItem::where('set_id', $set->id)->pluck('id');
    expect(CatalogItem::where('set_id', $set->id)->first()->primary_image_path)->toBeNull()
        ->and(MarketValue::whereIn('catalog_item_id', $ids)->exists())->toBeFalse();
});

test('the import command runs', function () {
    $this->artisan('catalog:import-set', ['ids' => ['sv8']])->assertSuccessful();

    expect(Set::where('slug', 'surging-sparks')->exists())->toBeTrue();
});
