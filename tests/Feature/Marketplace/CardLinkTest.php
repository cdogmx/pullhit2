<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketplaceListing;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seller = User::factory()->create(['username' => 'seller']);

    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->line->id,
        'name' => 'Obsidian Flames',
        'code' => 'OBF',
    ]);

    $this->card = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id,
        'set_id' => $this->set->id,
        'name' => 'Charizard ex',
        'number' => '223',
        'popularity' => 100,
    ]);
});

test('every word has to hit something', function () {
    // Matching any single word would bury the right card under every Charizard
    // ever printed. "charizard 223 obsidian" has to land on one.
    CatalogItem::factory()->create([
        'product_line_id' => $this->line->id,
        'set_id' => $this->set->id,
        'name' => 'Charizard',
        'number' => '105',
    ]);

    $hits = $this->actingAs($this->seller)
        ->getJson('/marketplace/card-search?q='.urlencode('charizard 223 obsidian'))
        ->assertOk()
        ->json('cards');

    expect($hits)->toHaveCount(1)
        ->and($hits[0]['id'])->toBe($this->card->id)
        ->and($hits[0]['number'])->toBe('223')
        ->and($hits[0]['set'])->toBe('Obsidian Flames');
});

test('a card can be found by its set code or its number alone', function () {
    foreach (['OBF', '223'] as $term) {
        $hits = $this->actingAs($this->seller)
            ->getJson("/marketplace/card-search?q={$term}")
            ->json('cards');

        expect(collect($hits)->pluck('id')->all())->toContain($this->card->id);
    }
});

test('a hit carries what the card is worth ungraded', function () {
    // The number a seller most wants while typing a price. Graded values are
    // left out: a slab's worth depends on a grade this search does not know.
    MarketValue::factory()->create([
        'catalog_item_id' => $this->card->id,
        'state_key' => 'NM',
        'grading_company_id' => null,
        'median' => 11000,
    ]);
    MarketValue::factory()->create([
        'catalog_item_id' => $this->card->id,
        'state_key' => 'psa-10',
        'grading_company_id' => GradingCompany::factory()->create(['slug' => 'psa'])->id,
        'median' => 90000,
    ]);

    $hits = $this->actingAs($this->seller)
        ->getJson('/marketplace/card-search?q=charizard')
        ->json('cards');

    expect($hits[0]['market_cents'])->toBe(11000);
});

test('the most looked-at card comes first', function () {
    // Usually the difference between the English print and an obscure reprint
    // nobody opens.
    $ignored = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id,
        'set_id' => $this->set->id,
        'name' => 'Charizard ex',
        'number' => '999',
        'popularity' => 0,
    ]);

    $hits = $this->actingAs($this->seller)
        ->getJson('/marketplace/card-search?q=charizard')
        ->json('cards');

    expect($hits[0]['id'])->toBe($this->card->id)
        ->and(collect($hits)->pluck('id'))->toContain($ignored->id);
});

test('a one-character query returns nothing rather than the whole catalogue', function () {
    expect($this->actingAs($this->seller)->getJson('/marketplace/card-search?q=c')->json('cards'))
        ->toBeEmpty();
});

test('a guest cannot search the catalogue through the listing form', function () {
    $this->getJson('/marketplace/card-search?q=charizard')->assertRedirect('/login');
});

test('a listing shows the market price beside the asking price', function () {
    // The thing an eBay listing cannot show a buyer.
    MarketValue::factory()->create([
        'catalog_item_id' => $this->card->id,
        'state_key' => 'NM',
        'grading_company_id' => null,
        'median' => 10000,
        'n_sales' => 12,
    ]);

    $listing = MarketplaceListing::factory()->create([
        'user_id' => $this->seller->id,
        'catalog_item_id' => $this->card->id,
        'price_cents' => 12500,
    ]);

    $this->get("/marketplace/{$listing->id}")->assertInertia(fn (Assert $page) => $page
        ->where('listing.market.cents', 10000)
        ->where('listing.market.state', 'NM')
        ->where('listing.market.sales', 12));
});

test('an unlinked listing simply has no market to show', function () {
    $listing = MarketplaceListing::factory()->create([
        'user_id' => $this->seller->id,
        'catalog_item_id' => null,
    ]);

    $this->get("/marketplace/{$listing->id}")
        ->assertInertia(fn (Assert $page) => $page->where('listing.market', null));
});

test('editing a linked listing shows the card already attached', function () {
    // Otherwise the form looks like nothing was ever linked, and the seller
    // links it a second time.
    $listing = MarketplaceListing::factory()->create([
        'user_id' => $this->seller->id,
        'catalog_item_id' => $this->card->id,
    ]);

    $this->actingAs($this->seller)->get("/marketplace/{$listing->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.card.id', $this->card->id)
            ->where('listing.card.number', '223'));
});
