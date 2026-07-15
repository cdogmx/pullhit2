<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\ValueSnapshot;
use App\Models\Vertical;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

/** Seed a today + yesterday value snapshot so the DAILY move equals $trend. */
function seedSnapshots(CatalogItem $item, int $latestMedian, float $trend, int $nSales = 8, bool $estimated = false): void
{
    $baseline = $trend <= -100.0 ? $latestMedian : (int) round($latestMedian / (1 + $trend / 100));

    foreach ([[1, $baseline], [0, $latestMedian]] as [$daysAgo, $median]) {
        ValueSnapshot::create([
            'catalog_item_id' => $item->id,
            'state_key' => 'NM',
            'median_cents' => $median,
            'n_sales' => $nSales,
            'is_estimated' => $estimated,
            'captured_on' => Carbon::now()->subDays($daysAgo)->toDateString(),
        ]);
    }
}

function moverCard(ProductLine $line, string $name, float $trend, int $median = 5000, array $overrides = []): CatalogItem
{
    $item = CatalogItem::factory()->for($line)->create([
        'name' => $name,
        'primary_image_path' => "images/{$name}.jpg",
        ...$overrides,
    ]);

    // A real market value powers the line/set filter dropdowns (hasRankableValue).
    MarketValue::factory()->for($item, 'catalogItem')->create([
        'grading_company_id' => null,
        'is_estimated' => false,
        'median' => $median,
        'n_sales' => 8,
    ]);

    seedSnapshots($item, $median, $trend);

    return $item;
}

beforeEach(function () {
    // Movers are cached by the latest snapshot day; every test shares "today",
    // so clear it to keep tests isolated.
    Cache::flush();

    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
});

test('it splits movers into gainers (desc) and losers (asc)', function () {
    moverCard($this->line, 'Big Gainer', 40.0);
    moverCard($this->line, 'Small Gainer', 5.0);
    moverCard($this->line, 'Big Loser', -35.0);
    moverCard($this->line, 'Small Loser', -4.0);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('movers')
            ->where('window', 'daily')
            ->has('gainers', 2)
            ->has('losers', 2)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'Big Gainer')) // largest rise first
            ->where('gainers.0.trend', fn ($t) => abs($t - 40) < 0.5)
            ->where('losers.0.name', fn ($n) => str_contains($n, 'Big Loser')) // largest drop first
            ->where('losers.0.trend', fn ($t) => abs($t + 35) < 0.5));
});

test('it computes the signed dollar change over the window', function () {
    // +25% daily on a $50 value → yesterday ≈ $40, change ≈ +$10 (1000c).
    moverCard($this->line, 'Riser', 25.0, 5000);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('gainers.0.change', fn ($c) => abs($c - 1000) <= 2));
});

test('it excludes estimated, penny, thin, and flat values', function () {
    // Estimated → excluded.
    $est = CatalogItem::factory()->for($this->line)->create(['primary_image_path' => 'a.jpg']);
    seedSnapshots($est, 5000, 30.0, 8, true);

    // Penny card (below the floor) → excluded.
    moverCard($this->line, 'Penny', 90.0, 100);

    // Too few sales → excluded.
    $thin = CatalogItem::factory()->for($this->line)->create(['primary_image_path' => 'b.jpg']);
    seedSnapshots($thin, 5000, 30.0, 1);

    // Flat (0% move) → excluded from both.
    moverCard($this->line, 'Flat', 0.0);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('gainers', 0)
            ->has('losers', 0));
});

test('a stale card (not re-priced recently) is not shown', function () {
    // A fresh card sets "now"; the stale card (last snapshot 10 days ago) then
    // fails the freshness gate and is excluded, while the fresh one ranks.
    moverCard($this->line, 'Fresh Riser', 20.0);

    $stale = CatalogItem::factory()->for($this->line)->create(['name' => 'Stale', 'primary_image_path' => 's.jpg']);
    ValueSnapshot::create(['catalog_item_id' => $stale->id, 'state_key' => 'NM', 'median_cents' => 9000, 'n_sales' => 8, 'is_estimated' => false, 'captured_on' => Carbon::now()->subDays(10)->toDateString()]);
    ValueSnapshot::create(['catalog_item_id' => $stale->id, 'state_key' => 'NM', 'median_cents' => 5000, 'n_sales' => 8, 'is_estimated' => false, 'captured_on' => Carbon::now()->subDays(11)->toDateString()]);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('gainers', 1)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'Fresh Riser')));
});

test('the window selects daily vs longer-horizon moves', function () {
    $item = CatalogItem::factory()->for($this->line)->create(['name' => 'Slow Riser', 'primary_image_path' => 'sr.jpg']);
    MarketValue::factory()->for($item, 'catalogItem')->create(['grading_company_id' => null, 'is_estimated' => false, 'median' => 6500, 'n_sales' => 8]);

    $mk = fn (int $day, int $m) => ValueSnapshot::create(['catalog_item_id' => $item->id, 'state_key' => 'NM', 'median_cents' => $m, 'n_sales' => 8, 'is_estimated' => false, 'captured_on' => Carbon::now()->subDays($day)->toDateString()]);
    $mk(0, 6500);  // today
    $mk(1, 6500);  // flat day-over-day
    $mk(31, 5000); // but +30% over 30 days

    // Daily: no move → not listed.
    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('window', 'daily')->has('gainers', 0));

    // 30-day: +30% gainer.
    $this->get('/movers?window=30d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('window', '30d')
            ->has('gainers', 1)
            ->where('gainers.0.trend', fn ($t) => abs($t - 30) < 0.5));
});

test('graded values are never ranked (ungraded only)', function () {
    // Snapshots only carry NM/SEALED; graded slabs never enter the series.
    $item = CatalogItem::factory()->for($this->line)->create(['primary_image_path' => 'g.jpg']);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('gainers', 0));
});

test('the type filter narrows to singles or sealed', function () {
    moverCard($this->line, 'A Single', 30.0); // Single is the factory default
    moverCard($this->line, 'A Box', 25.0, 5000, ['item_type' => ItemType::Sealed]);

    $this->get('/movers?type=single')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('type', 'single')
            ->has('gainers', 1)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'A Single')));

    $this->get('/movers?type=sealed')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('type', 'sealed')
            ->has('gainers', 1)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'A Box')));
});

test('the set filter scopes to one set and implies its line', function () {
    $setA = Set::factory()->for($this->line)->create(['slug' => 'set-a', 'name' => 'Set A']);
    $setB = Set::factory()->for($this->line)->create(['slug' => 'set-b', 'name' => 'Set B']);
    moverCard($this->line, 'In A', 30.0, 5000, ['set_id' => $setA->id]);
    moverCard($this->line, 'In B', 40.0, 5000, ['set_id' => $setB->id]);

    $this->get('/movers?set=set-a')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('set', 'set-a')
            ->where('line', 'pokemon')
            ->has('sets', 2)
            ->has('gainers', 1)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'In A')));
});

test('the line filter scopes movers to one product line', function () {
    moverCard($this->line, 'Pokemon Riser', 30.0);

    $other = ProductLine::factory()->for(Vertical::factory()->create(['slug' => 'tcg2']))
        ->create(['slug' => 'one-piece', 'name' => 'One Piece']);
    moverCard($other, 'One Piece Riser', 45.0);

    $this->get('/movers?line=pokemon')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('line', 'pokemon')
            ->has('gainers', 1)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'Pokemon Riser')));
});
