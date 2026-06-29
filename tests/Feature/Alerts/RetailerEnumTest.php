<?php

use App\Enums\Retailer;

it('extracts retailer product ids from product URLs', function (Retailer $retailer, string $url, ?string $expected) {
    expect($retailer->externalIdFromUrl($url))->toBe($expected);
})->with([
    'amazon /dp/' => [Retailer::Amazon, 'https://www.amazon.com/dp/B0GWKHNR4K', 'B0GWKHNR4K'],
    'bestbuy new /product/' => [Retailer::BestBuy, 'https://www.bestbuy.com/product/pokemon-tcg-series-2/JJG2TL3VR2', 'JJG2TL3VR2'],
    'bestbuy legacy .p' => [Retailer::BestBuy, 'https://www.bestbuy.com/site/x/6525410.p?skuId=6525410', '6525410'],
    'walmart /ip/' => [Retailer::Walmart, 'https://www.walmart.com/ip/some-name/15296401808', '15296401808'],
    'target /A-' => [Retailer::Target, 'https://www.target.com/p/slug/-/A-89542109', '89542109'],
    'costco .product.' => [Retailer::Costco, 'https://www.costco.com/slug.product.4000351081.html', '4000351081'],
    'pokemon center (none)' => [Retailer::PokemonCenter, 'https://www.pokemoncenter.com/product/abc', null],
]);
