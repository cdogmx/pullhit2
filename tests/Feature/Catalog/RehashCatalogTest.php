<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->pokemon = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'pokemon',
    ]);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->pokemon->id,
        'slug' => 'white-flare',
        'language' => 'en',
    ]);
});

/** A row written under the OLD rules: hashed over every attribute. */
function legacyRow(string $name, string $number, array $attributes, array $externalIds = []): CatalogItem
{
    $item = new CatalogItem;
    $item->forceFill([
        'vertical_id' => test()->vertical->id,
        'product_line_id' => test()->pokemon->id,
        'set_id' => test()->set->id,
        'item_type' => 'single',
        'name' => $name,
        'number' => $number,
        'attributes' => $attributes,
        'external_ids' => $externalIds === [] ? null : $externalIds,
        // The old scheme's hash: everything, so richness forked it.
        'identity_hash' => hash('sha256', $name.$number.json_encode($attributes)),
        'base_key' => hash('sha256', $name.$number.'base'.json_encode($attributes)),
    ])->save();

    return $item;
}

test('it merges rows that only differed by descriptive facets', function () {
    $rich = legacyRow('Sewaddle', '1', [
        'language' => 'en', 'rarity' => 'Common', 'variant' => 'reverse_holo',
        'hp' => 50, 'type' => 'Grass', 'illustrator' => 'Oswaldo KATO',
    ], ['ptcgio_id' => 'rsv10pt5-1']);

    $lean = legacyRow('Sewaddle', '1', [
        'language' => 'en', 'rarity' => 'Common', 'variant' => 'reverse_holo',
    ], ['tcgplayer_product_id' => 642116]);

    expect(CatalogItem::count())->toBe(2);

    $this->artisan('catalog:rehash', ['--execute' => true])->assertSuccessful();

    // One row survives — the one that knows more — and it inherits the other's ids.
    expect(CatalogItem::count())->toBe(1);
    $survivor = CatalogItem::sole();
    expect($survivor->id)->toBe($rich->id)
        ->and($survivor->external_ids)->toHaveKey('ptcgio_id', 'rsv10pt5-1')
        ->and($survivor->external_ids)->toHaveKey('tcgplayer_product_id', 642116);

    expect(CatalogItem::find($lean->id))->toBeNull();
});

test('it keeps genuinely distinct printings apart', function () {
    legacyRow('Sewaddle', '1', ['language' => 'en', 'variant' => 'normal']);
    legacyRow('Sewaddle', '1', ['language' => 'en', 'variant' => 'holo']);
    legacyRow('Sewaddle', '1', ['language' => 'en', 'variant' => 'normal', 'finish' => 'ball']);
    legacyRow('Sewaddle', '1', ['language' => 'ja', 'variant' => 'normal']);

    $this->artisan('catalog:rehash', ['--execute' => true])->assertSuccessful();

    expect(CatalogItem::count())->toBe(4);

    // The English printings group under one card; the Japanese one does not.
    $all = CatalogItem::all();
    $en = $all->filter(fn ($i) => $i->getAttribute('attributes')['language'] === 'en');
    $ja = $all->firstWhere(fn ($i) => $i->getAttribute('attributes')['language'] === 'ja');

    expect($en)->toHaveCount(3)
        ->and($en->pluck('base_key')->unique())->toHaveCount(1)
        ->and($ja->base_key)->not->toBe($en->first()->base_key);
});

test('it strips a collector number left in the name, wherever it sits', function () {
    legacyRow('Spewpa - 008/080', '8', ['language' => 'en', 'variant' => 'normal']);
    legacyRow("Team Rocket's Dugtrio - 101/217 (Team Rocket)", '101', ['language' => 'en', 'variant' => 'holo']);

    $this->artisan('catalog:rehash', ['--execute' => true])->assertSuccessful();

    expect(CatalogItem::where('number', '8')->value('name'))->toBe('Spewpa')
        ->and(CatalogItem::where('number', '101')->value('name'))->toBe("Team Rocket's Dugtrio (Team Rocket)");
});

test('--skip-names leaves names alone', function () {
    legacyRow('Spewpa - 008/080', '8', ['language' => 'en', 'variant' => 'normal']);

    $this->artisan('catalog:rehash', ['--execute' => true, '--skip-names' => true])
        ->assertSuccessful();

    expect(CatalogItem::sole()->name)->toBe('Spewpa - 008/080');
});

test('a merge re-points collection and wishlist rows instead of dropping them', function () {
    $rich = legacyRow('Sewaddle', '1', [
        'language' => 'en', 'variant' => 'reverse_holo', 'hp' => 50,
    ]);
    $lean = legacyRow('Sewaddle', '1', ['language' => 'en', 'variant' => 'reverse_holo']);

    // Someone owns the copy that is about to be absorbed.
    $user = User::factory()->create();
    DB::table('collection_items')->insert([
        'user_id' => $user->id, 'catalog_item_id' => $lean->id,
        'quantity' => 1, 'condition' => 'near_mint',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // ...and the absorbed row carries derived valuation data, which should not
    // survive to double-count against the survivor's own.
    MarketValue::factory()->create(['catalog_item_id' => $lean->id]);

    $this->artisan('catalog:rehash', ['--execute' => true])->assertSuccessful();

    expect(CatalogItem::count())->toBe(1)
        ->and(DB::table('collection_items')->where('catalog_item_id', $rich->id)->count())->toBe(1)
        ->and(DB::table('collection_items')->where('catalog_item_id', $lean->id)->count())->toBe(0)
        ->and(MarketValue::where('catalog_item_id', $lean->id)->count())->toBe(0);
});

test('a dry run writes nothing', function () {
    legacyRow('Spewpa - 008/080', '8', ['language' => 'en', 'variant' => 'normal', 'hp' => 40]);
    legacyRow('Spewpa - 008/080', '8', ['language' => 'en', 'variant' => 'normal']);

    $before = CatalogItem::orderBy('id')->get(['id', 'name', 'identity_hash', 'base_key'])->toArray();

    $this->artisan('catalog:rehash')->assertSuccessful();

    expect(CatalogItem::orderBy('id')->get(['id', 'name', 'identity_hash', 'base_key'])->toArray())
        ->toBe($before);
});
