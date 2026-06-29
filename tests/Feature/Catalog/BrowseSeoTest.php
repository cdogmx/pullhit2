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
        ->create(['name' => 'Pikachu', 'number' => '25', 'attributes' => ['language' => 'en']]);
});

test('the set landing renders the browse page filtered to that set with SEO meta', function () {
    $this->get('/browse/pokemon/surging-sparks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/browse')
            ->where('filters.set', 'surging-sparks')
            ->where('filters.product_line', 'pokemon')
            ->where('seo.heading', 'Surging Sparks')
            ->where('seo.title', 'Surging Sparks — Pokémon cards & prices'),
        );
});

test('the product-line landing renders filtered to that line', function () {
    $this->get('/browse/pokemon')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.product_line', 'pokemon')
            ->where('seo.heading', 'Pokémon'),
        );
});

test('an unknown set 404s', function () {
    $this->get('/browse/pokemon/not-a-real-set')->assertNotFound();
});

test('an unknown product line 404s', function () {
    $this->get('/browse/nope')->assertNotFound();
});

test('the sitemap index references the pages sitemap', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('content-type', 'application/xml')
        ->assertSee('/sitemap-pages.xml');
});

test('the pages sitemap lists set landing URLs', function () {
    // Brand & set landings live in the pages sub-sitemap (the index only points
    // at the sub-sitemaps).
    $this->get('/sitemap-pages.xml')
        ->assertOk()
        ->assertHeader('content-type', 'application/xml')
        ->assertSee('/browse/pokemon/surging-sparks')
        ->assertSee('/browse/pokemon</loc>', false);
});
