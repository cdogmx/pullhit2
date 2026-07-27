<?php

use App\Actions\Valuation\IngestPricechartingComps;
use App\Actions\Valuation\MaybeRefreshPricecharting;
use App\Enums\ItemType;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

/** A single with a given value (cents), rarity, and set release year. */
function pcSingle(?int $valueCents = 2000, string $rarity = 'Rare', ?int $year = 2023): CatalogItem
{
    $set = Set::factory()->create(['released_at' => $year ? "{$year}-01-01" : null]);
    $single = CatalogItem::factory()->for($set)->create([
        'item_type' => ItemType::Single,
        'pc_synced_at' => null,
        'attributes' => ['rarity' => $rarity],
    ]);

    if ($valueCents !== null) {
        MarketValue::factory()->for($single, 'catalogItem')->create([
            'state_key' => 'NM', 'grading_company_id' => null, 'median' => $valueCents,
        ]);
    }

    return $single;
}

test('a never-synced sealed product dispatches a pull on view', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => null]);

    app(MaybeRefreshPricecharting::class)($box);

    Queue::assertPushed(RefreshPricechartingData::class, fn ($j) => $j->catalogItemId === $box->id);
});

test('an already-synced sealed product never re-pulls on view (once-ever)', function () {
    // Synced recently OR long ago — either way it is never auto-re-pulled.
    foreach ([now()->subDay(), now()->subDays(90)] as $syncedAt) {
        Queue::fake();
        $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => $syncedAt]);

        app(MaybeRefreshPricecharting::class)($box);

        Queue::assertNothingPushed();
    }
});

test('a valuable non-common single dispatches a pull on view', function () {
    $single = pcSingle(valueCents: 2000, rarity: 'Rare', year: 2023);

    app(MaybeRefreshPricecharting::class)($single);

    Queue::assertPushed(RefreshPricechartingData::class, fn ($j) => $j->catalogItemId === $single->id);
});

test('a single below the value floor is skipped', function () {
    app(MaybeRefreshPricecharting::class)(pcSingle(valueCents: 400, rarity: 'Rare')); // $4 < $5
    app(MaybeRefreshPricecharting::class)(pcSingle(valueCents: null)); // no value at all

    Queue::assertNothingPushed();
});

test('a modern common/uncommon single is skipped even above the floor', function () {
    foreach (['Common', 'Uncommon', 'C', 'UC'] as $rarity) {
        app(MaybeRefreshPricecharting::class)(pcSingle(valueCents: 2000, rarity: $rarity, year: 2023));
    }

    Queue::assertNothingPushed();
});

test('a vintage common single above the floor still dispatches', function () {
    $vintage = pcSingle(valueCents: 2000, rarity: 'Common', year: 1999);

    app(MaybeRefreshPricecharting::class)($vintage);

    Queue::assertPushed(RefreshPricechartingData::class, fn ($j) => $j->catalogItemId === $vintage->id);
});

test('the job itself skips an already-synced item unless forced', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => now()->subDay()]);
    $ingest = Mockery::mock(IngestPricechartingComps::class);
    $ingest->shouldNotReceive('__invoke');

    (new RefreshPricechartingData($box->id))->handle($ingest, app(OxylabsClient::class));
});
