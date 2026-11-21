<?php

use App\Actions\Catalog\ImportTcgcsvSet;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\TcgcsvGame;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function opProduct(int $id, string $name, string $number, string $rarity = 'SR'): array
{
    return [
        'productId' => $id, 'name' => $name,
        'imageUrl' => "https://tcgplayer-cdn.tcgplayer.com/product/{$id}_200w.jpg",
        'extendedData' => [
            ['name' => 'Rarity', 'value' => $rarity],
            ['name' => 'Number', 'value' => $number],
            ['name' => 'Color', 'value' => 'Red'],
        ],
    ];
}

beforeEach(function () {
    Storage::fake('s3');

    Http::fake([
        'tcgcsv.com/tcgplayer/68/groups' => Http::response(['results' => [
            ['groupId' => 24736, 'name' => "The World's Strongest Warriors", 'abbreviation' => 'OP17', 'publishedOn' => '2026-08-28T00:00:00'],
            ['groupId' => 24749, 'name' => 'ST-31: Starter Deck 31 RED Monkey.D.Luffy', 'abbreviation' => 'ST-31', 'publishedOn' => '2026-07-31T00:00:00'],
        ]]),
        'tcgcsv.com/tcgplayer/68/24736/products' => Http::response(['results' => [
            opProduct(1, 'Shanks (020)', 'OP17-020'),
            opProduct(2, 'Shanks (020) (Alternate Art)', 'OP17-020'),
            opProduct(3, 'Roronoa Zoro (EB04-007) (SP)', 'EB04-007'),
            opProduct(4, 'Vista (TR)', 'OP17-011', 'TR'),
            opProduct(5, 'Buggy (051) (Parallel)', 'OP17-051'),
            opProduct(6, 'Mr.2.Bon.Kurei(Bentham)', 'OP17-036'),
            ['productId' => 99, 'name' => "The World's Strongest Warriors Booster Box", 'extendedData' => []],
        ]]),
        'tcgcsv.com/tcgplayer/68/24736/prices' => Http::response(['results' => [
            ['productId' => 2, 'subTypeName' => 'Foil', 'marketPrice' => 120.00],
        ]]),
        'tcgcsv.com/tcgplayer/68/24749/products' => Http::response(['results' => [
            opProduct(10, 'Monkey.D.Luffy', 'ST31-001', 'L'),
        ]]),
        'tcgcsv.com/tcgplayer/68/24749/prices' => Http::response(['results' => []]),
        'tcgplayer-cdn.tcgplayer.com/*' => Http::response('FAKE-PNG-BYTES', 200),
    ]);
});

test('One Piece products keep the plain name and file the printing as a finish', function () {
    app(ImportTcgcsvSet::class)(24736, game: TcgcsvGame::OnePiece);

    $rows = CatalogItem::all()->map(fn ($i) => [$i->name, $i->number, $i->finish])->sortBy(fn ($r) => implode('|', array_map('strval', $r)))->values()->all();

    expect($rows)->toBe([
        ['Buggy', 'OP17-051', 'alternate_art'],
        ['Mr.2.Bon.Kurei(Bentham)', 'OP17-036', null],
        ['Roronoa Zoro', 'EB04-007', 'sp'],
        ['Shanks', 'OP17-020', null],
        ['Shanks', 'OP17-020', 'alternate_art'],
        ['Vista', 'OP17-011', 'treasure_rare'],
    ]);
});

test('One Piece sets follow the existing slug and code conventions', function () {
    app(ImportTcgcsvSet::class)(24736, game: TcgcsvGame::OnePiece);
    app(ImportTcgcsvSet::class)(24749, game: TcgcsvGame::OnePiece);

    $op17 = Set::where('external_ids->tcgplayer_group_id', '24736')->firstOrFail();
    $st31 = Set::where('external_ids->tcgplayer_group_id', '24749')->firstOrFail();

    expect($op17->only(['slug', 'name', 'code', 'language', 'set_family']))->toBe([
        'slug' => 'one-piece-the-worlds-strongest-warriors-en',
        'name' => "The World's Strongest Warriors",
        'code' => 'OP17',
        'language' => 'en',
        'set_family' => "The World's Strongest Warriors",
    ])->and($st31->code)->toBe('ST31')
        ->and($st31->name)->toBe('Starter Deck 31 RED Monkey.D.Luffy');
});

test('a One Piece set we already hold keeps its name and slug, and a re-run duplicates nothing', function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'one-piece']);
    $stub = Set::factory()->create([
        'product_line_id' => $line->id, 'language' => 'en', 'code' => null,
        'slug' => 'one-piece-starter-deck-ex-31-en', 'name' => 'Starter Deck EX 31',
        'external_ids' => ['tcgplayer_group_id' => '24749'],
    ]);

    app(ImportTcgcsvSet::class)(24749, game: TcgcsvGame::OnePiece);
    app(ImportTcgcsvSet::class)(24749, game: TcgcsvGame::OnePiece);

    expect($stub->fresh()->only(['slug', 'name', 'code']))->toBe([
        'slug' => 'one-piece-starter-deck-ex-31-en', 'name' => 'Starter Deck EX 31', 'code' => 'ST31',
    ])->and(Set::count())->toBe(1)
        ->and(CatalogItem::where('set_id', $stub->id)->count())->toBe(1);
});
