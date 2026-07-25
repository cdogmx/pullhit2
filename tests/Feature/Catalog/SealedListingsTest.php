<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Support\Facades\Http;

/**
 * A sealed product is a different market from a single: retail search wording,
 * no condition/grade ladder, and the returned titles have to actually be this
 * product. Before this, a booster box page searched "<name> <set> Near Mint"
 * and filtered on language alone — so it listed near-mint singles.
 */
beforeEach(function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
    ]);

    $line = ProductLine::factory()->create(['slug' => 'lorcana', 'name' => 'Disney Lorcana']);
    $set = Set::factory()->for($line)->create([
        'slug' => 'attack-of-the-vine', 'name' => 'Attack of the Vine!', 'language' => 'en',
    ]);

    // Named just "Booster Box" — the game and set have to come from the query.
    $this->box = CatalogItem::factory()->for($line)->for($set)->create([
        'item_type' => 'sealed',
        'name' => 'Booster Box',
        'number' => null,
        'attributes' => ['language' => 'en', 'sealed_type' => 'booster_box'],
    ]);
});

function fakeSealedBrowse(array $titles): void
{
    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response([
            'itemSummaries' => array_map(fn (string $t, int $i) => [
                'title' => $t,
                'price' => ['value' => '100.00', 'currency' => 'USD'],
                'itemWebUrl' => 'https://ebay.com/itm/'.$i,
            ], $titles, array_keys($titles)),
        ]),
    ]);
}

test('a sealed product searches retail wording, not the singles condition ladder', function () {
    fakeSealedBrowse(['Disney Lorcana Attack of the Vine Booster Box Sealed']);

    $this->getJson("/api/v1/catalog/{$this->box->id}/listings")
        ->assertOk()
        ->assertJsonPath('selected', 'Sealed')
        ->assertJsonPath('ebay_options.0.label', 'Sealed')
        // No card conditions or grades are offered for a box.
        ->assertJsonPath('ebay_options', fn ($o) => ! in_array('PSA 10', array_column($o, 'label'), true)
            && ! in_array('Near Mint', array_column($o, 'label'), true));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'item_summary/search')) {
            return true;
        }

        $url = urldecode($request->url());

        // The game and set are folded in (the product name carries neither),
        // and "Near Mint" is nowhere near it.
        return str_contains($url, 'Disney Lorcana')
            && str_contains($url, 'Attack of the Vine!')
            && str_contains($url, 'Booster Box')
            && ! str_contains($url, 'Near Mint');
    });
});

test('it drops listings that are not this sealed product', function () {
    fakeSealedBrowse([
        'Disney Lorcana Attack of the Vine Booster Box Factory Sealed',  // keep
        'Disney Lorcana Attack of the Vine Elrond 145/204 Near Mint',    // a single
        'Disney Lorcana Azurite Sea Booster Box Sealed',                 // wrong set
        'Lot of 2 Disney Lorcana Attack of the Vine Booster Box',        // a lot
        'Disney Lorcana Attack of the Vine Booster Box EMPTY no packs',  // an empty
        'Disney Lorcana Attack of the Vine Booster Box CASE of 4',       // a case
    ]);

    $listings = $this->getJson("/api/v1/catalog/{$this->box->id}/listings")
        ->assertOk()
        ->json('listings');

    expect(array_column($listings, 'title'))
        ->toBe(['Disney Lorcana Attack of the Vine Booster Box Factory Sealed']);
});

test('a single still gets the condition ladder and its own query', function () {
    fakeSealedBrowse(['Elrond 145/204 Near Mint']);

    $single = CatalogItem::factory()->create(['name' => 'Elrond', 'number' => '145']);

    $this->getJson("/api/v1/catalog/{$single->id}/listings")
        ->assertOk()
        ->assertJsonPath('selected', 'Near Mint')
        ->assertJsonPath('ebay_options.0.label', 'Near Mint')
        ->assertJsonCount(1, 'listings');
});
