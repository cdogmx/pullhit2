<?php

use App\Support\Ebay\EbayBrowseClient;
use Illuminate\Support\Facades\Http;

test('search returns [] and makes no call when developer creds are unset', function () {
    config(['services.ebay.client_id' => null, 'services.ebay.client_secret' => null]);
    Http::fake();

    expect(app(EbayBrowseClient::class)->search('Pikachu'))->toBe([]);
    Http::assertNothingSent();
});

test('search parses listings and prefers the affiliate url', function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
        'services.ebay.marketplace_id' => 'EBAY_US',
    ]);
    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response(['itemSummaries' => [
            [
                'title' => 'Pikachu ex', 'price' => ['value' => '12.50', 'currency' => 'USD'],
                'image' => ['imageUrl' => 'https://img/1.jpg'], 'condition' => 'Used',
                'itemWebUrl' => 'https://ebay.com/itm/1',
                'itemAffiliateWebUrl' => 'https://ebay.com/itm/1?campid=5338',
            ],
        ]]),
    ]);

    $out = app(EbayBrowseClient::class)->search('Pikachu');

    expect($out)->toHaveCount(1)
        ->and($out[0]['price_cents'])->toBe(1250)
        ->and($out[0]['url'])->toBe('https://ebay.com/itm/1?campid=5338');
});
