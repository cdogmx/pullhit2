<?php

use App\Models\CatalogItem;
use App\Models\Collection;
use App\Models\CollectionItem;
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
    ]);
});

test('the listing form opens empty when nothing was carried over', function () {
    $this->actingAs($this->seller)->get('/marketplace/new')
        ->assertInertia(fn (Assert $page) => $page
            ->component('marketplace/form')
            ->where('prefill', null));
});

test('list for sale on a card page arrives knowing the card', function () {
    MarketValue::factory()->create([
        'catalog_item_id' => $this->card->id,
        'state_key' => 'NM',
        'grading_company_id' => null,
        'median' => 10000,
    ]);

    $this->actingAs($this->seller)->get("/marketplace/new?card={$this->card->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill.card.id', $this->card->id)
            ->where('prefill.card.number', '223')
            ->where('prefill.card.market_cents', 10000)
            ->where('prefill.category', 'raw_single')
            ->where('prefill.condition', 'NM')
            // The display name, so a variant printing says so in the title.
            ->where('prefill.title', $this->card->display_name.' #223 — Obsidian Flames'));
});

test('the price is never prefilled', function () {
    // A suggested number is not a suggestion; it is an anchor, and the price is
    // the one decision that has to be the seller's.
    MarketValue::factory()->create([
        'catalog_item_id' => $this->card->id,
        'state_key' => 'NM',
        'grading_company_id' => null,
        'median' => 10000,
    ]);

    $this->actingAs($this->seller)->get("/marketplace/new?card={$this->card->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('prefill', fn (Assert $p) => $p
                ->missing('price_cents')
                ->etc()));
});

test('list for sale on a graded holding carries the slab across', function () {
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $collection = Collection::factory()->create(['user_id' => $this->seller->id]);
    $holding = CollectionItem::factory()->create([
        'user_id' => $this->seller->id,
        'collection_id' => $collection->id,
        'catalog_item_id' => $this->card->id,
        'condition' => null,
        'grading_company_id' => $psa->id,
        'grade' => 10.0,
    ]);

    MarketValue::factory()->create([
        'catalog_item_id' => $this->card->id,
        'state_key' => $holding->stateKey(),
        'grading_company_id' => $psa->id,
        'median' => 90000,
    ]);
    MarketValue::factory()->create([
        'catalog_item_id' => $this->card->id,
        'state_key' => 'NM',
        'grading_company_id' => null,
        'median' => 10000,
    ]);

    $this->actingAs($this->seller)->get("/marketplace/new?holding={$holding->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill.category', 'graded_slab')
            ->where('prefill.grading_company_id', $psa->id)
            // 10.0 reads as "10" on a slab label, not "10.0".
            ->where('prefill.grade', '10')
            // A slab's grade replaces its condition; offering both offers a
            // contradiction.
            ->where('prefill.condition', null)
            // The PSA 10 price, not the raw one.
            ->where('prefill.card.market_cents', 90000));
});

test('a raw holding carries its own condition, not a default', function () {
    $collection = Collection::factory()->create(['user_id' => $this->seller->id]);
    $holding = CollectionItem::factory()->create([
        'user_id' => $this->seller->id,
        'collection_id' => $collection->id,
        'catalog_item_id' => $this->card->id,
        'condition' => 'LP',
    ]);

    $this->actingAs($this->seller)->get("/marketplace/new?holding={$holding->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill.category', 'raw_single')
            ->where('prefill.condition', 'LP'));
});

test('someone else\'s holding tells you nothing', function () {
    // The id is a guessable integer, so an unscoped lookup would turn the query
    // string into a way to read what anyone else owns, graded and valued.
    $other = User::factory()->create();
    $collection = Collection::factory()->create(['user_id' => $other->id]);
    $holding = CollectionItem::factory()->create([
        'user_id' => $other->id,
        'collection_id' => $collection->id,
        'catalog_item_id' => $this->card->id,
    ]);

    $this->actingAs($this->seller)->get("/marketplace/new?holding={$holding->id}")
        ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
});

test('a card id that is not a number is simply ignored', function () {
    $this->actingAs($this->seller)->get('/marketplace/new?card=nonsense&holding=nonsense')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
});

test('a prefill never edits an existing listing', function () {
    // ?card= on an edit URL must not quietly relink someone else's listing.
    $listing = MarketplaceListing::factory()->create([
        'user_id' => $this->seller->id,
        'title' => 'As written by the seller',
        'catalog_item_id' => null,
    ]);

    $this->actingAs($this->seller)->get("/marketplace/{$listing->id}/edit?card={$this->card->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.title', 'As written by the seller')
            ->where('listing.card', null)
            ->missing('prefill'));
});

test('a guest is sent to log in rather than to the form', function () {
    $this->get("/marketplace/new?card={$this->card->id}")->assertRedirect('/login');
});
