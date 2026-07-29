<?php

use App\Actions\Catalog\ImportLorcana;
use App\Actions\Catalog\ImportTcgcsvSet;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\TcgcsvGame;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');

    Http::fake([
        'tcgcsv.com/tcgplayer/3/groups' => Http::response(['results' => [
            ['groupId' => 24688, 'name' => 'ME05: Pitch Black', 'publishedOn' => '2026-07-17T00:00:00', 'categoryId' => 3],
            ['groupId' => 24326, 'name' => 'SV: White Flare', 'publishedOn' => '2025-07-18T00:00:00', 'categoryId' => 3],
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
    $r = app(ImportTcgcsvSet::class)(24688);

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
    app(ImportTcgcsvSet::class)(24688);
    app(ImportTcgcsvSet::class)(24688);

    $set = Set::where('slug', 'pitch-black')->first();
    expect(Set::where('slug', 'pitch-black')->count())->toBe(1)
        ->and(CatalogItem::where('set_id', $set->id)->count())->toBe(2);
});

test('it keeps the printed code when the group name carries a series prefix', function () {
    // The English ball-pattern printings live only on TCGplayer, so these sets
    // get a TCGCSV pass on top of a pokemontcg.io import. TCGplayer names that
    // group "SV: White Flare", which parses to the code "SV" — that must not
    // relabel the real printed code the game's own importer already stored.
    Http::fake([
        'tcgcsv.com/tcgplayer/3/24326/products' => Http::response(['results' => [
            [
                'productId' => 642290, 'name' => 'Sewaddle (Master Ball Pattern)',
                'extendedData' => [
                    ['name' => 'Rarity', 'value' => 'Common'],
                    ['name' => 'Number', 'value' => '001/086'],
                ],
            ],
            // Same shape, but TCGCSV puts the number BEFORE the parenthetical.
            [
                'productId' => 642291, 'name' => "Team Rocket's Dugtrio - 101/217 (Team Rocket)",
                'extendedData' => [
                    ['name' => 'Rarity', 'value' => 'Common'],
                    ['name' => 'Number', 'value' => '101/217'],
                ],
            ],
        ]]),
        'tcgcsv.com/tcgplayer/3/24326/prices' => Http::response(['results' => [
            ['productId' => 642290, 'subTypeName' => 'Holofoil', 'marketPrice' => 1.25],
            ['productId' => 642291, 'subTypeName' => 'Holofoil', 'marketPrice' => 0.75],
        ]]),
    ]);

    // Stand in for what pokemontcg.io already imported: the set must sit under
    // the product line the importer resolves, or it matches nothing and forks.
    $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
    $productLine = ProductLine::updateOrCreate(
        ['vertical_id' => $vertical->id, 'slug' => 'pokemon'],
        ['name' => 'Pokémon'],
    );
    $existing = Set::factory()->create([
        'product_line_id' => $productLine->id,
        'slug' => 'white-flare',
        'name' => 'White Flare',
        'code' => 'WHT',
        'language' => 'en',
    ]);

    app(ImportTcgcsvSet::class)(24326, withImages: false);

    expect(Set::where('slug', 'white-flare')->count())->toBe(1)
        ->and($existing->fresh()->code)->toBe('WHT');

    // ...and the pattern printing itself lands, keeping its parenthetical so it
    // is a distinct row from the plain card of the same number.
    $item = CatalogItem::where('set_id', $existing->id)->where('number', '1')->first();
    expect($item->name)->toBe('Sewaddle (Master Ball Pattern)')
        ->and($item->getAttribute('attributes')['variant'])->toBe('holo');

    // The collector number is stripped even when it sits before the
    // parenthetical, not only at the end of the name.
    expect(CatalogItem::where('set_id', $existing->id)->where('number', '101')->value('name'))
        ->toBe("Team Rocket's Dugtrio (Team Rocket)");
});

/** Lorcana lives under TCGplayer category 71 and has no finish axis. */
function fakeLorcanaFeed(): void
{
    Http::fake([
        'tcgcsv.com/tcgplayer/71/groups' => Http::response(['results' => [
            ['groupId' => 24666, 'name' => 'Attack of the Vine!', 'publishedOn' => '2026-07-17T00:00:00', 'categoryId' => 71],
        ]]),
        'tcgcsv.com/tcgplayer/71/24666/products' => Http::response(['results' => [
            [
                'productId' => 690200, 'name' => 'Mike Wazowski - Well-Rounded Entertainer',
                'cleanName' => 'Mike Wazowski Well Rounded Entertainer',
                'imageUrl' => 'https://tcgplayer-cdn.tcgplayer.com/product/690200_200w.jpg',
                'extendedData' => [
                    ['name' => 'Rarity', 'value' => 'Common'],
                    ['name' => 'Number', 'value' => '21/207'],
                ],
            ],
        ]]),
        'tcgcsv.com/tcgplayer/71/24666/prices' => Http::response(['results' => [
            ['productId' => 690200, 'subTypeName' => 'Normal', 'marketPrice' => 0.25],
            ['productId' => 690200, 'subTypeName' => 'Cold Foil', 'marketPrice' => 3.50],
        ]]),
        'tcgplayer-cdn.tcgplayer.com/*' => Http::response('FAKE-PNG-BYTES', 200),
    ]);
}

test('it imports a Lorcana set as one row per card, not one per finish', function () {
    fakeLorcanaFeed();

    $r = app(ImportTcgcsvSet::class)(24666, game: TcgcsvGame::Lorcana);

    expect($r)->toMatchArray(['set' => 'Attack of the Vine!', 'items' => 1]);

    $set = Set::where('slug', 'attack-of-the-vine')->first();
    expect($set->productLine->slug)->toBe('lorcana');

    // Lorcana has no holo axis in our catalog — Cold Foil must NOT spawn a
    // second row, and the anchor is the base price, not the foil premium.
    $items = CatalogItem::where('set_id', $set->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items->first()->name)->toBe('Mike Wazowski - Well-Rounded Entertainer') // " - Version" kept
        ->and($items->first()->number)->toBe('21')
        ->and($items->first()->getAttribute('attributes')['variant'])->toBe('normal');

    expect(MarketValue::where('catalog_item_id', $items->first()->id)->value('median'))->toBe(25);
});

test('a set TCGCSV seeded on release day is refined, not duplicated, when lorcana-api catches up', function () {
    fakeLorcanaFeed();
    app(ImportTcgcsvSet::class)(24666, game: TcgcsvGame::Lorcana);

    // lorcana-api publishes the set weeks later, keyed by ITS own set id. It must
    // land on the same row — otherwise the site shows the set twice, with every
    // card duplicated under the copy.
    Http::fake(['*lorcana-api.com*' => Http::response([[
        'Unique_ID' => 'AOV-021', 'Set_ID' => 'AOV', 'Set_Name' => 'Attack of the Vine!', 'Set_Num' => 13,
        'Name' => 'Mike Wazowski - Well-Rounded Entertainer', 'Card_Num' => 21, 'Rarity' => 'Common',
    ]])]);
    app(ImportLorcana::class)(['AOV'], withImages: false);

    $sets = Set::where('slug', 'attack-of-the-vine')->get();
    expect($sets)->toHaveCount(1);

    // Both source ids survive on the one row, so either importer finds it next time.
    expect($sets->first()->external_ids)
        ->toHaveKey('tcgplayer_group_id', '24666')
        ->toHaveKey('lorcana_set_id', 'AOV')
        // ...and the real printed code lorcana-api knows now fills in.
        ->and($sets->first()->code)->toBe('AOV');
});
