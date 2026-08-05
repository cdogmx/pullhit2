<?php

use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->pokemon = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'pokemon',
    ]);
});

function seriesLessSet(?string $code, ?string $released, string $name = 'A Set'): Set
{
    return Set::factory()->create([
        'product_line_id' => test()->pokemon->id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::random(5),
        'code' => $code,
        'series' => null,
        'released_at' => $released,
        'language' => 'ja',
    ]);
}

test('it reads the era from the set code', function () {
    $sv = seriesLessSet('SV11W', '2025-06-06', 'White Flare');
    $sm = seriesLessSet('SM10', '2019-03-01', 'Double Blaze');
    $xy = seriesLessSet('XY-P', '2013-08-01', 'XY Promos');
    $mega = seriesLessSet('M2a', '2025-11-28', 'MEGA Dream ex');

    test()->artisan('catalog:backfill-series', ['--execute' => true])->assertSuccessful();

    expect($sv->fresh()->series)->toBe('Scarlet & Violet')
        ->and($sm->fresh()->series)->toBe('Sun & Moon')
        ->and($xy->fresh()->series)->toBe('XY')
        ->and($mega->fresh()->series)->toBe('Mega Evolution');
});

test('SV and SM win over the bare S of the Sword & Shield era', function () {
    // "S12a" is Sword & Shield; "SV1a" and "SM11" must not be swept up by it.
    $swsh = seriesLessSet('S12a', '2022-12-02', 'VSTAR Universe');
    $sv = seriesLessSet('SV1a', '2023-03-10', 'Triplet Beat');
    $sm = seriesLessSet('SM11', '2019-05-31', 'Miracle Twin');

    test()->artisan('catalog:backfill-series', ['--execute' => true])->assertSuccessful();

    expect($swsh->fresh()->series)->toBe('Sword & Shield')
        ->and($sv->fresh()->series)->toBe('Scarlet & Violet')
        ->and($sm->fresh()->series)->toBe('Sun & Moon');
});

test('a set with no code falls back to its release date', function () {
    $old = seriesLessSet(null, '1997-03-05', 'Pokemon Jungle');
    $neo = seriesLessSet(null, '2000-07-07', 'Crossing the Ruins');

    test()->artisan('catalog:backfill-series', ['--execute' => true])->assertSuccessful();

    expect($old->fresh()->series)->toBe('Base')
        ->and($neo->fresh()->series)->toBe('Neo');
});

test('a set with neither code nor date still gets a reachable series', function () {
    $orphan = seriesLessSet(null, null, 'Mystery Product');

    test()->artisan('catalog:backfill-series', ['--execute' => true])->assertSuccessful();

    // Anything beats null: a series-less set appears under no browse tile at all.
    expect($orphan->fresh()->series)->toBe('Other');
});

test('it leaves sets that already have a series alone', function () {
    $already = Set::factory()->create([
        'product_line_id' => $this->pokemon->id,
        'code' => 'SV11W',
        'series' => 'Hand Curated',
        'released_at' => '2025-06-06',
    ]);

    test()->artisan('catalog:backfill-series', ['--execute' => true])->assertSuccessful();

    expect($already->fresh()->series)->toBe('Hand Curated');
});

test('a dry run writes nothing', function () {
    $set = seriesLessSet('SV11W', '2025-06-06');

    test()->artisan('catalog:backfill-series')->assertSuccessful();

    expect($set->fresh()->series)->toBeNull();
});
