<?php

use App\Models\CatalogItem;
use Illuminate\Support\Facades\Http;

test('the listings endpoint returns listings + affiliate urls and caches the fetch', function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
    ]);
    // The title has to name the card — the panel holds active asks to the same
    // identity gates sold comps get, so "X" is nobody's listing.
    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response(['itemSummaries' => [
            ['title' => 'Charizard 004 Base Set', 'price' => ['value' => '1.00', 'currency' => 'USD'], 'itemWebUrl' => 'https://ebay.com/itm/1'],
        ]]),
    ]);
    $item = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '004', 'attributes' => ['language' => 'en', 'variant' => 'normal']]);

    $this->getJson("/api/v1/catalog/{$item->id}/listings")
        ->assertOk()
        ->assertJsonCount(1, 'listings')
        ->assertJsonPath('configured', true)
        ->assertJsonPath('ebay_options.0.label', 'Near Mint')
        ->assertJsonPath('ebay_options.0.url', fn ($u) => str_contains($u, 'campid=5338') && str_contains($u, 'Near+Mint'));

    // Second call is served from cache — only the original token + search went out.
    $this->getJson("/api/v1/catalog/{$item->id}/listings")->assertOk();
    Http::assertSentCount(2);
});

test('the listings endpoint works (affiliate links only) when Browse is unconfigured', function () {
    config(['services.ebay.client_id' => null, 'services.ebay.client_secret' => null, 'services.ebay.campaign_id' => '5338']);
    Http::fake();
    $item = CatalogItem::factory()->create();

    $this->getJson("/api/v1/catalog/{$item->id}/listings")
        ->assertOk()
        ->assertJsonPath('configured', false)
        ->assertJsonCount(0, 'listings')
        ->assertJsonPath('ebay_options.0.url', fn ($u) => str_contains($u, 'campid=5338'));
});

test('the listings endpoint searches and filters by the card language', function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
    ]);
    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response(['itemSummaries' => [
            ['title' => 'Japanese Charizard 006 NM', 'price' => ['value' => '20.00', 'currency' => 'USD'], 'itemWebUrl' => 'https://ebay.com/itm/1'],
            ['title' => 'Charizard 006 English NM', 'price' => ['value' => '9.00', 'currency' => 'USD'], 'itemWebUrl' => 'https://ebay.com/itm/2'],
        ]]),
    ]);
    $item = CatalogItem::factory()->create([
        'name' => 'Charizard',
        'number' => '006',
        'attributes' => ['language' => 'ja', 'variant' => 'normal'],
    ]);

    $this->getJson("/api/v1/catalog/{$item->id}/listings")
        ->assertOk()
        // The English printing is a different market — it never shows up here.
        ->assertJsonCount(1, 'listings')
        ->assertJsonPath('listings.0.title', 'Japanese Charizard 006 NM')
        ->assertJsonPath('ebay_options.0.url', fn ($u) => str_contains(urldecode($u), 'Japanese'));

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'item_summary/search')
        || str_contains(urldecode($request->url()), 'Japanese'));
});

test('the listings endpoint accepts a condition/grade option', function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
    ]);
    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response(['itemSummaries' => [
            ['title' => 'Charizard 004 Base Set PSA 10', 'price' => ['value' => '50.00', 'currency' => 'USD'], 'itemWebUrl' => 'https://ebay.com/itm/2'],
            // A raw copy — right card, wrong slab for the chosen refinement.
            ['title' => 'Charizard 004 Base Set NM', 'price' => ['value' => '9.00', 'currency' => 'USD'], 'itemWebUrl' => 'https://ebay.com/itm/3'],
        ]]),
    ]);
    $item = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '004', 'attributes' => ['language' => 'en', 'variant' => 'normal']]);

    $this->getJson("/api/v1/catalog/{$item->id}/listings?option=PSA%2010")
        ->assertOk()
        ->assertJsonPath('selected', 'PSA 10')
        ->assertJsonCount(1, 'listings')
        ->assertJsonPath('listings.0.title', 'Charizard 004 Base Set PSA 10');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'item_summary/search')
            && str_contains(urldecode($request->url()), 'PSA 10');
    });
});

test('a single listing panel drops everything that is not this exact card', function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
    ]);

    $titles = [
        'Charmander 038 First Partners Series 1 Holo',        // keep
        'Pokemon GO Single Cards - YOU PICK - BIG QTY',       // bulk "you pick"
        'Pokemon First Partner Series 1 Kanto Set of 3',      // multi-card set
        '2023 Pokemon Classic Collection Charmander 001',     // a different Charmander
        'Charmander 038 First Partners Series 1 PSA 10',      // a slab, not raw
        'Lot of 20 Pokemon cards Charmander 038',             // a lot
    ];

    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response([
            'itemSummaries' => array_map(fn (string $t, int $i) => [
                'title' => $t,
                'price' => ['value' => '50.00', 'currency' => 'USD'],
                'itemWebUrl' => 'https://ebay.com/itm/'.$i,
            ], $titles, array_keys($titles)),
        ]),
    ]);

    $item = CatalogItem::factory()->create(['name' => 'Charmander', 'number' => '38', 'attributes' => ['language' => 'en', 'variant' => 'normal']]);

    $listings = $this->getJson("/api/v1/catalog/{$item->id}/listings")
        ->assertOk()
        ->json('listings');

    // "038" matches the card's "38" — leading zeros are how sellers write it.
    expect(array_column($listings, 'title'))
        ->toBe(['Charmander 038 First Partners Series 1 Holo']);
});

test('the collector number is a preference, not a hard gate', function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
    ]);

    // Plenty of sellers never write the number (One Piece codes especially).
    // Showing a name-matched listing beats showing an empty panel.
    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response(['itemSummaries' => [
            ['title' => 'One Piece TCG O-Nami Beginners Deck Party Winner', 'price' => ['value' => '5.00', 'currency' => 'USD'], 'itemWebUrl' => 'https://ebay.com/itm/1'],
        ]]),
    ]);

    $item = CatalogItem::factory()->create(['name' => 'O-Nami', 'number' => 'OP06-101', 'attributes' => ['language' => 'en', 'variant' => 'normal']]);

    $this->getJson("/api/v1/catalog/{$item->id}/listings")
        ->assertOk()
        ->assertJsonCount(1, 'listings');
});
