<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg', 'name' => 'Trading Card Games']);
    $this->line = ProductLine::factory()->for($this->vertical)->create([
        'slug' => 'pokemon', 'name' => 'Pokémon',
    ]);
    $this->set = Set::factory()->for($this->line)->create([
        'slug' => 'surging-sparks', 'name' => 'Surging Sparks', 'language' => 'en',
    ]);
    CatalogItem::factory()
        ->for($this->vertical)->for($this->line)->for($this->set)
        ->create([
            'name' => 'Pikachu', 'number' => '25',
            'attributes' => ['language' => 'en'],
            'primary_image_path' => 'https://img.test/pikachu.png',
        ]);
});

test('bare browse shows brand tiles', function () {
    $this->get('/browse')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/browse')
            ->where('mode', 'brands')
            ->where('tiles.0.kind', 'brand')
            ->where('tiles.0.slug', 'pokemon')
            ->where('tiles.0.count', 1)
            ->where('tiles.0.thumb', 'https://img.test/pikachu.png'),
        );
});

test('choosing a brand shows its set tiles with a thumbnail and language', function () {
    $this->get('/browse?product_line=pokemon')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'sets')
            ->where('tiles.0.kind', 'set')
            ->where('tiles.0.slug', 'surging-sparks')
            ->where('tiles.0.count', 1)
            ->where('tiles.0.language', 'en')
            ->where('tiles.0.thumb', 'https://img.test/pikachu.png')
            ->where('tileLanguages', ['en']),
        );
});

test('the SEO product-line landing also shows set tiles', function () {
    $this->get('/browse/pokemon')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'sets'));
});

test('choosing a set drops to cards', function () {
    $this->get('/browse?product_line=pokemon&set=surging-sparks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'cards')
            ->where('items.0.name', 'Pikachu'),
        );
});

test('the SEO set landing shows cards', function () {
    $this->get('/browse/pokemon/surging-sparks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'cards'));
});

test('a search query drops straight to cards', function () {
    $this->get('/browse?q=pika')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'cards')
            ->where('items.0.name', 'Pikachu'),
        );
});

test('a brand with multiple series shows series tiles, newest first', function () {
    $sv = Set::factory()->for($this->line)->create([
        'slug' => 'temporal-forces', 'series' => 'Scarlet & Violet',
        'language' => 'en', 'released_at' => '2024-03-22',
    ]);
    $swsh = Set::factory()->for($this->line)->create([
        'slug' => 'evolving-skies', 'series' => 'Sword & Shield',
        'language' => 'en', 'released_at' => '2021-08-27',
    ]);
    CatalogItem::factory()->for($this->vertical)->for($this->line)->for($sv)
        ->create(['number' => '1', 'primary_image_path' => 'https://img/sv.png']);
    CatalogItem::factory()->for($this->vertical)->for($this->line)->for($swsh)
        ->create(['number' => '1', 'primary_image_path' => 'https://img/swsh.png']);

    $this->get('/browse?product_line=pokemon')
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'series')
            ->where('tiles.0.kind', 'series')
            ->where('tiles.0.slug', 'Scarlet & Violet') // newest release first
            ->where('tiles.1.slug', 'Sword & Shield'),
        );
});

test('choosing a series shows its sets', function () {
    Set::factory()->for($this->line)->create([
        'slug' => 'temporal-forces', 'series' => 'Scarlet & Violet', 'language' => 'en',
    ])->catalogItems()->save(
        CatalogItem::factory()->for($this->vertical)->for($this->line)->make(['number' => '1']),
    );

    $this->get('/browse?product_line=pokemon&series='.urlencode('Scarlet & Violet'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'sets')
            ->where('tiles.0.slug', 'temporal-forces'),
        );
});

test('a set with named subsets shows subset tiles (main first)', function () {
    $set = Set::factory()->for($this->line)->create([
        'slug' => 'brilliant-stars', 'language' => 'en',
    ]);
    foreach (['1', '2'] as $n) {
        CatalogItem::factory()->for($this->vertical)->for($this->line)->for($set)
            ->create(['number' => $n, 'primary_image_path' => 'https://img/m.png']);
    }
    CatalogItem::factory()->for($this->vertical)->for($this->line)->for($set)
        ->create(['number' => 'TG01', 'primary_image_path' => 'https://img/tg.png']);

    $this->get('/browse?product_line=pokemon&set=brilliant-stars')
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'subsets')
            ->where('tiles.0.slug', 'main')
            ->where('tiles.0.name', 'Main set')
            ->where('tiles.1.slug', 'TG')
            ->where('tiles.1.name', 'Trainer Gallery'),
        );
});

test('choosing a subset filters to its cards', function () {
    $set = Set::factory()->for($this->line)->create([
        'slug' => 'brilliant-stars', 'language' => 'en',
    ]);
    foreach (['1', '2'] as $n) {
        CatalogItem::factory()->for($this->vertical)->for($this->line)->for($set)
            ->create(['number' => $n]);
    }
    CatalogItem::factory()->for($this->vertical)->for($this->line)->for($set)
        ->create(['number' => 'TG01', 'name' => 'Gallery Card']);

    $this->get('/browse?product_line=pokemon&set=brilliant-stars&subset=TG')
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'cards')
            ->has('items', 1)
            ->where('items.0.number', 'TG01'),
        );

    $this->get('/browse?product_line=pokemon&set=brilliant-stars&subset=main')
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'cards')
            ->has('items', 2),
        );
});

test('empty brands and sets are hidden', function () {
    // A second, empty set should not appear as a tile.
    Set::factory()->for($this->line)->create([
        'slug' => 'empty-set', 'name' => 'Empty Set', 'language' => 'en',
    ]);

    $this->get('/browse?product_line=pokemon')
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'sets')
            ->where('tiles', fn ($tiles) => collect($tiles)->pluck('slug')->doesntContain('empty-set')),
        );
});
