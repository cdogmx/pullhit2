<?php

use App\Actions\Alerts\CheckStockAlerts;
use App\Models\RetailerLink;
use App\Models\TrackedProduct;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.oxylabs', [
        'username' => 'u', 'password' => 'p',
        'endpoint' => 'https://realtime.oxylabs.io/v1/queries',
    ]);
    config()->set('services.x', [
        'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        'access_token' => 'at', 'access_token_secret' => 'ats',
    ]);
});

/** Fake Oxylabs to return $content (array = parsed; string = HTML) + X endpoints. */
function fakeOxylabs(mixed $content): void
{
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => $content]]], 200),
        'upload.twitter.com/*' => Http::response(['media_id_string' => '42'], 200),
        'api.twitter.com/*' => Http::response(['data' => ['id' => '999']], 200),
        '*' => Http::response('', 200),
    ]);
}

function product(array $overrides = []): TrackedProduct
{
    return TrackedProduct::create(array_merge([
        'name' => 'Surging Sparks ETB',
        'target_price' => 5000,
        'currency' => 'USD',
        'check_interval_minutes' => 15,
        'is_active' => true,
    ], $overrides));
}

function makeLink(TrackedProduct $p, string $retailer, array $overrides = []): RetailerLink
{
    return $p->links()->create(array_merge([
        'retailer' => $retailer,
        'url' => "https://example.test/{$retailer}/product",
        'external_id' => '123456',
    ], $overrides));
}

function evaluate(RetailerLink $l): array
{
    return app(CheckStockAlerts::class)->evaluate($l->load('product.catalogItem'));
}

it('tweets a Walmart link in stock at/below target', function () {
    fakeOxylabs([
        'general' => ['title' => 'Surging Sparks ETB', 'main_image' => 'https://img.test/x.jpg'],
        'price' => ['price' => 49.99, 'currency' => 'USD'],
        'fulfillment' => ['out_of_stock' => false],
    ]);

    $result = evaluate(makeLink(product(['target_price' => 5000]), 'walmart'));

    expect($result['qualified'])->toBeTrue()->and($result['tweeted'])->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.twitter.com/2/tweets'));
});

it('does not qualify when Walmart reports out_of_stock', function () {
    fakeOxylabs([
        'general' => ['title' => 'X'],
        'price' => ['price' => 10.0, 'currency' => 'USD'],
        'fulfillment' => ['out_of_stock' => true],
    ]);

    expect(evaluate(makeLink(product(), 'walmart'))['qualified'])->toBeFalse();
});

it('does not qualify when Best Buy is sold out', function () {
    fakeOxylabs(['title' => 'X', 'price' => ['price' => 10.0, 'currency' => 'USD'], 'is_sold_out' => true]);
    expect(evaluate(makeLink(product(), 'bestbuy'))['qualified'])->toBeFalse();
});

it('qualifies when Best Buy is in stock at/below target', function () {
    fakeOxylabs(['title' => 'X', 'price' => ['price' => 10.0, 'currency' => 'USD'], 'is_sold_out' => false]);
    expect(evaluate(makeLink(product(['target_price' => 5000]), 'bestbuy'))['qualified'])->toBeTrue();
});

it('infers Target stock from price presence', function () {
    fakeOxylabs(['title' => 'X', 'price' => 19.99, 'currency' => 'USD']);
    expect(evaluate(makeLink(product(['target_price' => 5000]), 'target'))['qualified'])->toBeTrue();
});

it('parses schema.org JSON-LD for retailers without a dedicated source', function () {
    $html = '<html><head><script type="application/ld+json">'
        .json_encode([
            '@type' => 'Product', 'name' => 'Pokemon Box', 'image' => ['https://img.test/p.jpg'],
            'offers' => ['@type' => 'Offer', 'price' => '39.99', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock'],
        ]).'</script></head><body></body></html>';

    fakeOxylabs($html);

    $result = evaluate(makeLink(product(['target_price' => 5000]), 'pokemon_center'));
    expect($result['qualified'])->toBeTrue();
});

it('does not tweet when the price is above target', function () {
    fakeOxylabs(['title' => 'X', 'price' => 99.0, 'currency' => 'USD', 'is_sold_out' => false]);
    expect(evaluate(makeLink(product(['target_price' => 5000]), 'bestbuy'))['tweeted'])->toBeFalse();
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.twitter.com'));
});

it('only tweets on the rising edge per link', function () {
    fakeOxylabs(['general' => ['title' => 'X'], 'price' => ['price' => 10.0, 'currency' => 'USD'], 'fulfillment' => ['out_of_stock' => false]]);
    $l = makeLink(product(['target_price' => 5000]), 'walmart');

    expect(evaluate($l)['tweeted'])->toBeTrue();
    expect(evaluate($l->fresh())['tweeted'])->toBeFalse();
});

it('records an error without tweeting when the fetch fails', function () {
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response('boom', 500),
        'api.twitter.com/*' => Http::response(['data' => ['id' => '999']], 200),
    ]);

    $result = evaluate(makeLink(product(), 'walmart'));
    expect($result['error'])->toBeTrue();
    expect($result['snapshot'])->toBeNull();
});

it('composes "<headline> in stock at <Retailer> for <price>" with link url', function () {
    $p = product(['name' => 'Wilds Unknown booster', 'currency' => 'USD']);
    $l = makeLink($p, 'target', ['url' => 'https://www.target.com/p/-/A-123']);

    $text = app(CheckStockAlerts::class)->composeTweet($l->load('product'), [
        'title' => 'Some long retailer title', 'price' => 599, 'currency' => 'USD',
        'stock' => null, 'image' => null, 'in_stock' => true,
    ]);

    expect($text)->toContain('Wilds Unknown booster in stock at Target for $5.99')
        ->toContain('target.com/p/-/A-123')
        ->not->toContain('Some long retailer title');
});

it('attaches the product image to the tweet', function () {
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => [
            'general' => ['title' => 'X', 'main_image' => 'https://img.test/x.jpg'],
            'price' => ['price' => 10.0, 'currency' => 'USD'],
            'fulfillment' => ['out_of_stock' => false],
        ]]]], 200),
        'img.test/*' => Http::response('JPEGBYTES', 200),
        'upload.twitter.com/*' => Http::response(['media_id_string' => '42'], 200),
        'api.twitter.com/*' => Http::response(['data' => ['id' => '999']], 200),
    ]);

    evaluate(makeLink(product(['target_price' => 5000]), 'walmart'));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.twitter.com/2/tweets')
        && in_array('42', data_get($r->data(), 'media.media_ids', []), true));
});
