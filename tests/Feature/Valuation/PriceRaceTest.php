<?php

use App\Actions\Valuation\BuildPriceRace;
use App\Actions\Valuation\ResolveRaceSources;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\SaleObservation;
use App\Models\Set;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();

    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration', 'name' => '30th Celebration', 'language' => 'en',
    ]);

    $this->card = fn (string $name) => CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $this->set->id,
        'name' => $name, 'primary_image_path' => 'https://img.test/x.png',
        'attributes' => ['language' => 'en'],
    ]);

    $this->sale = fn (CatalogItem $item, string $day, int $cents) => SaleObservation::create([
        'catalog_item_id' => $item->id, 'venue' => 'ebay', 'price' => $cents,
        'currency' => 'USD', 'condition' => 'NM', 'observed_at' => $day.' 00:00:00',
        'is_synthetic' => false, 'source_listing_id' => uniqid(), 'raw' => ['title' => $item->name],
    ]);
});

/** Resolve a set to its cards and race them, the way the controller does. */
function raceSet(string $slug = '30th-celebration'): ?array
{
    $ids = app(ResolveRaceSources::class)([['type' => 'set', 'slug' => $slug]])['ids'];

    return app(BuildPriceRace::class)($ids);
}

/** Give a card enough sales on enough days to clear the window's minimum. */
function race(callable $sale, CatalogItem $card, array $days, int $cents): void
{
    foreach ($days as $day) {
        $sale($card, $day, $cents);
        $sale($card, $day, $cents);
    }
}

test('a single sale is not a price', function () {
    // 19 August saw eight sales across eight cards. A median of one sale is an
    // anecdote, and plotting it would draw a market that was not there.
    $solo = ($this->card)('Lonely');
    ($this->sale)($solo, '2026-09-01', 50000);

    expect(raceSet())->toBeNull();
});

test('the race starts when there is a field to race', function () {
    // A set opens with a handful of presale sales and two days of none at all.
    // Those frames draw a chart that has gone blank, not a quiet market.
    $cards = collect(range(1, 6))->map(fn ($n) => ($this->card)("Card {$n}"));
    $days = ['2026-09-01', '2026-09-02', '2026-09-03'];

    // One card trades early and alone; the field arrives on the 1st.
    ($this->sale)($cards[0], '2026-08-20', 10000);
    ($this->sale)($cards[0], '2026-08-20', 10000);

    foreach ($cards as $i => $card) {
        race($this->sale, $card, $days, 10000 * ($i + 1));
    }

    $result = raceSet();

    expect($result['frames'][0]['day'])->toBe('2026-09-01')
        // The volume ribbon still covers the quiet weeks before it.
        ->and($result['volume'][0]['day'])->toBe('2026-08-20');
});

test('bars are ranked by value, highest first', function () {
    $cards = collect(range(1, 6))->map(fn ($n) => ($this->card)("Card {$n}"));

    foreach ($cards as $i => $card) {
        race($this->sale, $card, ['2026-09-01'], 10000 * ($i + 1));
    }

    $frame = raceSet()['frames'][0];
    $values = array_column($frame['bars'], 'value');

    expect($values)->toBe([60000, 50000, 40000, 30000, 20000, 10000]);
});

test('a day with no sales still counts as a day', function () {
    // Skipping it speeds the tape up exactly where the market was slowest.
    $cards = collect(range(1, 6))->map(fn ($n) => ($this->card)("Card {$n}"));

    foreach ($cards as $i => $card) {
        race($this->sale, $card, ['2026-09-01', '2026-09-04'], 10000 * ($i + 1));
    }

    $days = array_column(raceSet()['frames'], 'day');

    expect($days)->toBe(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04']);
});

test('the page renders for the featured set with no slug', function () {
    $cards = collect(range(1, 6))->map(fn ($n) => ($this->card)("Card {$n}"));

    foreach ($cards as $i => $card) {
        race($this->sale, $card, ['2026-09-01'], 10000 * ($i + 1));
    }

    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);

    $this->get('/price-race')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('price-race')
        ->where('race.title', '30th Celebration')
        ->has('race.frames')
        ->has('race.volume'));
});

test('a set with nothing sold is a 404, not an empty chart', function () {
    ($this->card)('Never sold');

    $this->get('/price-race/30th-celebration')->assertNotFound();
});
