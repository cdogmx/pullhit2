<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

test('the home page renders the welcome page with catalog sections', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->has('brands')
            ->has('trending')
            ->has('movers')
            ->has('recent')
            ->has('popularSets')
            ->has('popularSearches')
            ->has('community.points')
            ->has('community.levels')
            ->where('community.month', now()->format('F')));
});

test('popular searches are the most-viewed card in each busy brand', function () {
    Cache::flush(); // the home sections are cached; ensure a fresh compute

    $pokemon = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $onepiece = ProductLine::factory()->create(['slug' => 'one-piece', 'name' => 'One Piece']);
    $pokeSet = Set::factory()->create(['product_line_id' => $pokemon->id]);
    $opSet = Set::factory()->create(['product_line_id' => $onepiece->id]);

    // The most-viewed card in each brand should surface as a search chip.
    CatalogItem::factory()->create(['product_line_id' => $pokemon->id, 'set_id' => $pokeSet->id, 'name' => 'Charizard ex', 'popularity' => 500, 'primary_image_path' => 'a.jpg']);
    CatalogItem::factory()->create(['product_line_id' => $pokemon->id, 'set_id' => $pokeSet->id, 'name' => 'Bidoof', 'popularity' => 5, 'primary_image_path' => 'b.jpg']);
    CatalogItem::factory()->create(['product_line_id' => $onepiece->id, 'set_id' => $opSet->id, 'name' => 'Monkey D. Luffy', 'popularity' => 400, 'primary_image_path' => 'c.jpg']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('popularSearches', fn ($terms) => collect($terms)->contains('Charizard ex')
                && collect($terms)->contains('Monkey D. Luffy')
                && ! collect($terms)->contains('Bidoof')));
});
