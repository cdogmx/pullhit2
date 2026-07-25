<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The browse search runs inside the brand → series → set scope the pickers show:
 * a brand searches only that brand, a series only that series, and a set turns
 * the box into a filter over that set's cards rather than a fresh search.
 */
beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);

    $this->poke = ProductLine::factory()->for($this->vertical)->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->other = ProductLine::factory()->for($this->vertical)->create(['slug' => 'onepiece', 'name' => 'One Piece']);

    $this->sv = Set::factory()->for($this->poke)->create([
        'slug' => 'surging-sparks', 'name' => 'Surging Sparks',
        'series' => 'Scarlet & Violet', 'language' => 'en', 'released_at' => '2024-11-08',
    ]);
    $this->swsh = Set::factory()->for($this->poke)->create([
        'slug' => 'evolving-skies', 'name' => 'Evolving Skies',
        'series' => 'Sword & Shield', 'language' => 'en', 'released_at' => '2021-08-27',
    ]);
    $this->op = Set::factory()->for($this->other)->create([
        'slug' => 'romance-dawn', 'name' => 'Romance Dawn', 'language' => 'en',
    ]);

    $card = fn (ProductLine $line, Set $set, string $name, string $number) => CatalogItem::factory()
        ->for($this->vertical)->for($line)->for($set)
        ->create(['name' => $name, 'number' => $number, 'attributes' => ['language' => 'en']]);

    $card($this->poke, $this->sv, 'Pikachu ex', '4');
    $card($this->poke, $this->swsh, 'Pikachu VMAX', '44');
    $card($this->other, $this->op, 'Pikachu Lookalike', '7');
});

test('a search inside a brand only returns that brand', function () {
    $this->get('/browse?product_line=pokemon&q=pikachu')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'cards')
            ->where('items', fn ($items) => collect($items)->pluck('name')->sort()->values()->all()
                === ['Pikachu VMAX', 'Pikachu ex']),
        );
});

test('a search inside a series only returns that series', function () {
    $this->get('/browse?product_line=pokemon&series='.urlencode('Sword & Shield').'&q=pikachu')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'cards')
            ->has('items', 1)
            ->where('items.0.name', 'Pikachu VMAX'),
        );
});

test('the series scope reaches the filter option lists', function () {
    $this->get('/browse?product_line=pokemon&series='.urlencode('Scarlet & Violet'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Only the brand's series, newest first — and only that series' sets.
            ->where('options.series', ['Scarlet & Violet', 'Sword & Shield'])
            ->where('options.sets', fn ($sets) => collect($sets)->pluck('slug')->all() === ['surging-sparks']),
        );
});

test('a set filter keeps the binder order instead of ranking by relevance', function () {
    // "Pikachu" is an exact name hit and would outrank "Pikachu ex" on relevance;
    // inside a set the collector-number order stands (4 before 100).
    CatalogItem::factory()->for($this->vertical)->for($this->poke)->for($this->sv)
        ->create(['name' => 'Pikachu', 'number' => '100', 'attributes' => ['language' => 'en']]);

    $this->get('/browse?product_line=pokemon&set=surging-sparks&q=pikachu')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'cards')
            ->has('items', 2)
            ->where('items.0.name', 'Pikachu ex')
            ->where('items.1.name', 'Pikachu'),
        );

    // Without the set, the same query ranks the exact name first.
    $this->get('/browse?product_line=pokemon&q=pikachu')
        ->assertInertia(fn (Assert $page) => $page->where('items.0.name', 'Pikachu'));
});

test('a set filter that matches nothing does not get auto-corrected', function () {
    // A half-typed filter term must not be rewritten under the user.
    $this->get('/browse?product_line=pokemon&set=surging-sparks&q=pikch')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 0)
            ->where('autoCorrectedTo', null)
            ->where('didYouMean', null),
        );

    // Outside a set the same typo still auto-corrects to a real search.
    $this->get('/browse?product_line=pokemon&q=pikch')
        ->assertInertia(fn (Assert $page) => $page->where('autoCorrectedTo', 'Pikachu ex'));
});

test('the SEO set landing seeds the series so the scope pickers show the full path', function () {
    $this->get('/browse/pokemon/surging-sparks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.product_line', 'pokemon')
            ->where('filters.series', 'Scarlet & Violet')
            ->where('filters.set', 'surging-sparks'),
        );
});

test('suggestions stay inside the browsed brand', function () {
    $unscoped = $this->getJson('/search/suggest?q=pikachu')->assertOk()->json();

    expect(collect($unscoped['cards'])->pluck('name')->sort()->values()->all())
        ->toBe(['Pikachu Lookalike', 'Pikachu VMAX', 'Pikachu ex']);

    $scoped = $this->getJson('/search/suggest?q=pikachu&product_line=pokemon')
        ->assertOk()
        ->json();

    expect(collect($scoped['cards'])->pluck('name')->sort()->values()->all())
        ->toBe(['Pikachu VMAX', 'Pikachu ex'])
        // Already inside the brand — there's nothing to suggest jumping to.
        ->and($scoped['brands'])->toBe([]);
});

test('suggestions stay inside the browsed series', function () {
    $scoped = $this->getJson('/search/suggest?q=pikachu&product_line=pokemon&series='.urlencode('Sword & Shield'))
        ->assertOk()
        ->json();

    expect(collect($scoped['cards'])->pluck('name')->all())->toBe(['Pikachu VMAX'])
        ->and(collect($scoped['sets'])->pluck('name')->all())->toBe([]);
});
