<?php

use App\Models\CatalogItem;
use App\Models\CatalogItemSlugAlias;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg', 'name' => 'Trading Card Games']);
    $this->line = ProductLine::factory()->for($this->vertical)->create([
        'slug' => 'pokemon', 'name' => 'Pokémon',
    ]);

    // The expansion the promos were printed for.
    $this->expansion = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration', 'name' => '30th Celebration', 'language' => 'en',
        'code' => 'ME', 'series' => 'Mega Evolution', 'released_at' => '2026-09-16',
    ]);
    CatalogItem::factory()->for($this->vertical)->for($this->line)->for($this->expansion)
        ->create(['name' => 'Mega Charizard ex', 'number' => '1']);

    // The promo pile TCGplayer actually sells them in.
    $this->pile = Set::factory()->for($this->line)->create([
        'slug' => 'mega-evolution-promo', 'name' => 'Mega Evolution Promo', 'language' => 'en',
        'code' => 'ME', 'series' => 'Mega Evolution', 'released_at' => '2025-09-26',
    ]);
    $this->promo = CatalogItem::factory()->for($this->vertical)->for($this->line)->for($this->pile)
        ->create(['name' => 'Pikachu ex (30th Celebration)', 'number' => '107']);
    $this->unrelated = CatalogItem::factory()->for($this->vertical)->for($this->line)->for($this->pile)
        ->create(['name' => 'Mega Lucario ex', 'number' => '12']);
});

test('a dry run reports the move and changes nothing', function () {
    $this->artisan('catalog:refile', [
        'from' => 'mega-evolution-promo',
        'into' => '30th Celebration Promos',
        '--matching' => '%30th Celebration%',
    ])->assertSuccessful();

    expect(Set::where('name', '30th Celebration Promos')->exists())->toBeFalse()
        ->and($this->promo->fresh()->set_id)->toBe($this->pile->id);
});

test('the matching cards move and the rest stay put', function () {
    $this->artisan('catalog:refile', [
        'from' => 'mega-evolution-promo',
        'into' => '30th Celebration Promos',
        '--matching' => '%30th Celebration%',
        '--execute' => true,
    ])->assertSuccessful();

    $target = Set::where('name', '30th Celebration Promos')->firstOrFail();

    expect($this->promo->fresh()->set_id)->toBe($target->id)
        ->and($this->unrelated->fresh()->set_id)->toBe($this->pile->id);
});

test('the new set is templated on the expansion, not on the promo pile', function () {
    // The run was printed for the 30th Celebration; it belongs to that release,
    // not to the date the promo group happened to open.
    $this->artisan('catalog:refile', [
        'from' => 'mega-evolution-promo',
        'into' => '30th Celebration Promos',
        '--matching' => '%30th Celebration%',
        '--execute' => true,
    ]);

    $target = Set::where('name', '30th Celebration Promos')->firstOrFail();

    expect($target->slug)->toBe('30th-celebration-promos')
        ->and($target->series)->toBe('Mega Evolution')
        ->and($target->released_at->toDateString())->toBe('2026-09-16')
        // No external ids: re-importing the promo group must still land on the
        // promo group's own set, not on this one.
        ->and($target->external_ids)->toBeNull();
});

test('the old URL redirects instead of 404ing', function () {
    $slug = $this->promo->slug;

    $this->artisan('catalog:refile', [
        'from' => 'mega-evolution-promo',
        'into' => '30th Celebration Promos',
        '--matching' => '%30th Celebration%',
        '--execute' => true,
    ]);

    expect(CatalogItemSlugAlias::where('set_id', $this->pile->id)->where('slug', $slug)->exists())
        ->toBeTrue();

    $this->get("/pokemon/mega-evolution-promo/{$slug}")
        ->assertRedirect("/pokemon/30th-celebration-promos/{$slug}");
});

test('the refiled promos become a tile on the expansion', function () {
    $this->artisan('catalog:refile', [
        'from' => 'mega-evolution-promo',
        'into' => '30th Celebration Promos',
        '--matching' => '%30th Celebration%',
        '--execute' => true,
    ]);

    $this->get('/browse?product_line=pokemon&set=30th-celebration')
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'subsets')
            ->where('tiles.0.name', 'Main set')
            ->where('tiles.1.name', 'Promos')
            ->where('tiles.1.set_slug', '30th-celebration-promos')
            ->where('tiles.1.count', 1));
});

test('a promo set with no parent expansion stays a set of its own', function () {
    // "SWSH Black Star Promos" is not a subset of anything — nothing is named
    // "SWSH" — so the Promos suffix must not swallow it.
    $promos = Set::factory()->for($this->line)->create([
        'slug' => 'swsh-black-star-promos', 'name' => 'SWSH Black Star Promos',
        'language' => 'en', 'series' => 'Sword & Shield',
    ]);
    CatalogItem::factory()->for($this->vertical)->for($this->line)->for($promos)
        ->create(['number' => 'SWSH001']);

    $this->get('/browse?product_line=pokemon&series='.urlencode('Sword & Shield'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tiles', fn ($tiles) => collect($tiles)->pluck('slug')->contains('swsh-black-star-promos')));
});

test('refiling into the set the cards are already in is refused', function () {
    $this->artisan('catalog:refile', [
        'from' => 'mega-evolution-promo',
        'into' => 'Mega Evolution Promo',
        '--execute' => true,
    ])->assertFailed();

    expect($this->promo->fresh()->set_id)->toBe($this->pile->id);
});
