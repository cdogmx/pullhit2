<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();

    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration', 'name' => '30th Celebration', 'language' => 'en',
    ]);

    $this->card = function (Set $set, string $name, int $median, bool $estimated = false) {
        $item = CatalogItem::factory()->create([
            'product_line_id' => $this->line->id, 'set_id' => $set->id,
            'name' => $name, 'primary_image_path' => 'https://img.test/'.$name.'.png',
            'attributes' => ['language' => 'en'],
        ]);
        MarketValue::factory()->for($item)->create([
            'state_key' => 'NM', 'grading_company_id' => null,
            'median' => $median, 'is_estimated' => $estimated, 'n_sales' => 3,
        ]);

        return $item;
    };
});

test('no featured set means no section', function () {
    ($this->card)($this->set, 'Pikachu', 5000);

    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('featured', null));
});

test('featuring a set puts its best cards on the home page', function () {
    ($this->card)($this->set, 'Pikachu', 5000);
    ($this->card)($this->set, 'Charizard', 90000);

    $this->artisan('catalog:feature-set', ['set' => '30th-celebration', '--blurb' => 'Just landed.'])
        ->assertSuccessful();

    $this->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('featured.name', '30th Celebration')
        ->where('featured.blurb', 'Just landed.')
        ->where('featured.href', '/browse/pokemon/30th-celebration')
        // Ordered by what pulled big, not by views — a week-old set has none.
        ->where('featured.cards.0.name', 'Charizard')
        ->has('featured.cards', 2));
});

test('featuring a set brings its subsets with it', function () {
    // The 30th ships a Classic Collection and a promo run as their own sets.
    // Someone told to look at the 30th Celebration means all three.
    $promos = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration-promos', 'name' => '30th Celebration Promos', 'language' => 'en',
    ]);
    ($this->card)($this->set, 'Pikachu', 5000);
    ($this->card)($promos, 'Umbreon ex', 40000);

    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);

    $this->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('featured.cards.0.name', 'Umbreon ex')
        ->has('featured.cards', 2));
});

test('a guessed price never becomes a headline', function () {
    // A card nobody has sold yet still carries a synthetic placeholder, and the
    // priciest of those is an invention. One such card was about to lead the
    // section at $339.52 on seven placeholder observations and no sales.
    ($this->card)($this->set, 'Real card', 5000);
    ($this->card)($this->set, 'Never sold', 99000, estimated: true);

    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);

    $this->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('featured.cards.0.name', 'Real card')
        ->has('featured.cards', 1));
});

test('the spot lapses on its own', function () {
    ($this->card)($this->set, 'Pikachu', 5000);
    $this->artisan('catalog:feature-set', ['set' => '30th-celebration', '--days' => 21]);

    $this->travel(22)->days();
    Cache::flush();

    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('featured', null));
});

test('a set can be taken down early', function () {
    ($this->card)($this->set, 'Pikachu', 5000);
    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);
    $this->artisan('catalog:feature-set', ['set' => '30th-celebration', '--clear' => true]);
    Cache::flush();

    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('featured', null));
});

test('an unknown slug features nothing', function () {
    $this->artisan('catalog:feature-set', ['set' => 'no-such-set'])->assertFailed();
});
