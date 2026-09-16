<?php

use App\Actions\Valuation\ResolveRaceSources;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\PriceRace;
use App\Models\ProductLine;
use App\Models\SaleObservation;
use App\Models\Set;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();

    $this->user = User::factory()->create(['username' => 'racer']);
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration', 'name' => '30th Celebration',
        'language' => 'en', 'series' => 'Mega Evolution',
    ]);

    $this->card = function (string $name, int $median = 5000, int $sales = 20, ?Set $set = null) {
        $item = CatalogItem::factory()->create([
            'product_line_id' => $this->line->id,
            'set_id' => ($set ?? $this->set)->id,
            'name' => $name,
            'item_type' => ItemType::Single,
            'attributes' => ['language' => 'en'],
        ]);
        MarketValue::factory()->for($item)->create([
            'state_key' => 'NM', 'grading_company_id' => null,
            'median' => $median, 'is_estimated' => false, 'n_sales' => $sales,
        ]);

        return $item;
    };

    $this->sale = fn (CatalogItem $item, string $day, int $cents) => SaleObservation::create([
        'catalog_item_id' => $item->id, 'venue' => 'ebay', 'price' => $cents,
        'currency' => 'USD', 'condition' => 'NM', 'observed_at' => $day.' 00:00:00',
        'is_synthetic' => false, 'source_listing_id' => uniqid(), 'raw' => ['title' => $item->name],
    ]);
});

test('a set source brings its subsets with it', function () {
    $promos = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration-promos', 'name' => '30th Celebration Promos', 'language' => 'en',
    ]);
    $main = ($this->card)('Pikachu');
    $promo = ($this->card)('Umbreon ex', set: $promos);

    $ids = app(ResolveRaceSources::class)([['type' => 'set', 'slug' => '30th-celebration']])['ids'];

    expect($ids)->toContain($main->id)->toContain($promo->id);
});

test('a race is cards, not boxes', function () {
    // A sealed product's price moves for its own reasons and dwarfs the singles
    // inside it, so one booster box would sit at the top for the whole tape.
    $single = ($this->card)('Pikachu');
    $box = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $this->set->id,
        'name' => 'Booster Box', 'item_type' => ItemType::Sealed,
        'attributes' => ['language' => 'en'],
    ]);

    $ids = app(ResolveRaceSources::class)([['type' => 'set', 'slug' => '30th-celebration']])['ids'];

    expect($ids)->toContain($single->id)->not->toContain($box->id);
});

test('an oversized selection keeps the valuable cards that actually trade', function () {
    // Sorting a brand by price alone picks the vintage grails, which are also
    // the least liquid — fewer than five of them sell in the same week, so the
    // field never forms and a brand race came back with a single frame.
    $grail = ($this->card)('Untouchable grail', median: 9_000_00, sales: 1);
    $mover = ($this->card)('Expensive and moving', median: 8_000_00, sales: 40);

    // Fill past the cap so the tail actually gets cut.
    for ($i = 0; $i < ResolveRaceSources::CANDIDATE_CAP; $i++) {
        ($this->card)("Filler {$i}", median: 100, sales: 30);
    }

    $ids = app(ResolveRaceSources::class)([['type' => 'set', 'slug' => '30th-celebration']])['ids'];

    expect($ids)->toContain($mover->id)->not->toContain($grail->id);
});

test('a hand-picked list races exactly those cards', function () {
    $wanted = collect([1, 2, 3])->map(fn ($n) => ($this->card)("Picked {$n}"));
    $ignored = ($this->card)('Not picked');

    $resolved = app(ResolveRaceSources::class)([
        ['type' => 'cards', 'ids' => $wanted->pluck('id')->all()],
    ]);

    expect($resolved['ids'])->toHaveCount(3)
        ->and($resolved['capped'])->toBeFalse()
        ->and($resolved['ids'])->not->toContain($ignored->id);
});

test('sources stay a spec, so a set race picks up cards added later', function () {
    // The 30th Celebration gained four cards by hand the day this was written.
    // A race saved the day before should race them.
    ($this->card)('Original');
    $race = PriceRace::create([
        'user_id' => $this->user->id, 'name' => 'Set race',
        'sources' => [['type' => 'set', 'slug' => '30th-celebration']],
    ]);

    $late = ($this->card)('Added later');

    expect(app(ResolveRaceSources::class)($race->sources)['ids'])->toContain($late->id);
});

test('a race can be built, watched and shared', function () {
    $cards = collect(range(1, 6))->map(fn ($n) => ($this->card)("Card {$n}"));

    foreach ($cards as $i => $card) {
        foreach (['2026-09-01', '2026-09-02'] as $day) {
            ($this->sale)($card, $day, 10000 * ($i + 1));
            ($this->sale)($card, $day, 10000 * ($i + 1));
        }
    }

    $this->actingAs($this->user)->post('/races', [
        'name' => 'Chase cards of the 30th',
        'description' => 'The ones worth opening for.',
        'is_public' => true,
        'sources' => [['type' => 'set', 'slug' => '30th-celebration']],
        'options' => ['top' => 10, 'window' => 7],
    ])->assertRedirect();

    $race = PriceRace::firstOrFail();

    expect($race->slug)->toBe('chase-cards-of-the-30th');

    // A shared link works for someone with no account.
    $this->get("/races/{$race->slug}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('price-race')
        ->where('race.title', 'Chase cards of the 30th')
        ->where('race.owner', 'racer')
        ->has('race.frames'));
});

test('a race nothing has sold is refused rather than saved blank', function () {
    ($this->card)('Never sold');

    $this->actingAs($this->user)->post('/races', [
        'name' => 'Empty', 'sources' => [['type' => 'cards', 'ids' => [999999]]],
    ])->assertSessionHasErrors('sources');

    expect(PriceRace::count())->toBe(0);
});

test('a private race is nobody else\'s to watch', function () {
    $race = PriceRace::create([
        'user_id' => $this->user->id, 'name' => 'Private', 'is_public' => false,
        'sources' => [['type' => 'set', 'slug' => '30th-celebration']],
    ]);

    $this->get("/races/{$race->slug}")->assertNotFound();
    $this->actingAs(User::factory()->create())->get("/races/{$race->slug}")->assertNotFound();
});

test('only the owner can edit or delete a race', function () {
    $race = PriceRace::create([
        'user_id' => $this->user->id, 'name' => 'Mine',
        'sources' => [['type' => 'set', 'slug' => '30th-celebration']],
    ]);
    $other = User::factory()->create();

    $this->actingAs($other)->get("/races/{$race->slug}/edit")->assertForbidden();
    $this->actingAs($other)->delete("/races/{$race->slug}")->assertForbidden();
    $this->actingAs($this->user)->delete("/races/{$race->slug}")->assertRedirect();

    expect(PriceRace::count())->toBe(0);
});

test('two races of the same name get their own links', function () {
    // Races are shared by URL; a collision would hand someone another race.
    foreach ([1, 2] as $ignored) {
        PriceRace::create([
            'user_id' => $this->user->id, 'name' => 'Same name',
            'sources' => [['type' => 'set', 'slug' => '30th-celebration']],
        ]);
    }

    expect(PriceRace::pluck('slug')->all())->toBe(['same-name', 'same-name-2']);
});
