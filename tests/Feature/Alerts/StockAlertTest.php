<?php

use App\Actions\Alerts\CheckStockAlerts;
use App\Models\StockAlert;
use App\Support\Amazon\AmazonProductClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.oxylabs', [
        'username' => 'u',
        'password' => 'p',
        'endpoint' => 'https://realtime.oxylabs.io/v1/queries',
    ]);
    config()->set('services.x', [
        'consumer_key' => 'ck',
        'consumer_secret' => 'cs',
        'access_token' => 'at',
        'access_token_secret' => 'ats',
    ]);
});

/** Fake the Oxylabs parsed product payload + the X tweet endpoint. */
function fakeAmazon(array $content): void
{
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => $content]]], 200),
        'api.twitter.com/*' => Http::response(['data' => ['id' => '999']], 200),
    ]);
}

function alert(array $overrides = []): StockAlert
{
    return StockAlert::create(array_merge([
        'asin' => 'B0GWKHNR4K',
        'target_price' => 599,
        'currency' => 'USD',
        'check_interval_minutes' => 15,
        'is_active' => true,
    ], $overrides));
}

it('parses price to cents and reads in-stock text', function () {
    $client = new AmazonProductClient();

    $out = $client->normalize([
        'title' => 'Test Booster Box',
        'price' => 5.49,
        'currency' => 'USD',
        'stock' => 'In Stock',
    ]);

    expect($out['price'])->toBe(549)
        ->and($out['in_stock'])->toBeTrue()
        ->and($out['title'])->toBe('Test Booster Box');
});

it('treats unavailable text as out of stock', function () {
    $out = (new AmazonProductClient())->normalize([
        'price' => 5.0,
        'stock' => 'Currently unavailable.',
    ]);

    expect($out['in_stock'])->toBeFalse();
});

it('tweets when a watched product is in stock at or below target', function () {
    fakeAmazon(['title' => 'Cheap Box', 'price' => 5.99, 'currency' => 'USD', 'stock' => 'In Stock']);
    $a = alert(['target_price' => 599]);

    $result = app(CheckStockAlerts::class)->evaluate($a);

    expect($result['qualified'])->toBeTrue()
        ->and($result['tweeted'])->toBeTrue();

    $a->refresh();
    expect($a->last_qualified)->toBeTrue()
        ->and($a->last_price)->toBe(599)
        ->and($a->last_tweeted_at)->not->toBeNull();

    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.twitter.com'));
});

it('does not tweet when the price is above target', function () {
    fakeAmazon(['title' => 'Pricey Box', 'price' => 9.99, 'stock' => 'In Stock']);
    $a = alert(['target_price' => 599]);

    $result = app(CheckStockAlerts::class)->evaluate($a);

    expect($result['qualified'])->toBeFalse()
        ->and($result['tweeted'])->toBeFalse();
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.twitter.com'));
});

it('does not tweet when out of stock', function () {
    fakeAmazon(['title' => 'OOS Box', 'price' => 5.0, 'stock' => 'Currently unavailable.']);
    $a = alert();

    expect(app(CheckStockAlerts::class)->evaluate($a)['tweeted'])->toBeFalse();
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.twitter.com'));
});

it('only tweets on the rising edge, not every check while still in stock', function () {
    fakeAmazon(['title' => 'Cheap Box', 'price' => 5.99, 'stock' => 'In Stock']);
    $a = alert();

    app(CheckStockAlerts::class)->evaluate($a); // first qualifying check → tweets
    $second = app(CheckStockAlerts::class)->evaluate($a->refresh()); // still in stock

    expect($second['qualified'])->toBeTrue()
        ->and($second['tweeted'])->toBeFalse();

    Http::assertSentCount(3); // 2 oxylabs fetches + exactly 1 tweet
});

it('records an error without tweeting when the fetch fails', function () {
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response('nope', 500),
        'api.twitter.com/*' => Http::response(['data' => ['id' => '999']], 200),
    ]);
    $a = alert();

    $result = app(CheckStockAlerts::class)->evaluate($a);

    expect($result['error'])->toBeTrue();
    $a->refresh();
    expect($a->last_error)->not->toBeNull()
        ->and($a->last_checked_at)->not->toBeNull();
});

it('composes a tweet: "<title> in stock at Amazon for <price>" + url', function () {
    $a = alert(['target_price' => 599, 'domain' => 'com']);
    $text = app(CheckStockAlerts::class)->composeTweet($a, [
        'title' => 'Surging Sparks ETB',
        'price' => 549,
        'currency' => 'USD',
        'stock' => 'In Stock',
        'in_stock' => true,
    ]);

    expect($text)->toContain('Surging Sparks ETB in stock at Amazon for $5.49')
        ->toContain('amazon.com/dp/B0GWKHNR4K');
});

it('prefers the alert label over the long Amazon title as the headline', function () {
    $a = alert(['label' => 'Wilds Unknown booster', 'target_price' => 599]);
    $text = app(CheckStockAlerts::class)->composeTweet($a, [
        'title' => 'Ravensburger Disney Lorcana TCG: Wilds Unknown Single Booster Pack - 12 Cards - Packaging May Vary',
        'price' => 599,
        'currency' => 'USD',
    ]);

    expect($text)->toContain('Wilds Unknown booster in stock at Amazon for $5.99')
        ->not->toContain('Ravensburger');
});

it('uploads the product image and attaches it to the tweet', function () {
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => [
            'title' => 'Cheap Box', 'price' => 5.0, 'currency' => 'USD',
            'stock' => 'In Stock', 'images' => ['https://img.example/box.jpg'],
        ]]]], 200),
        'img.example/*' => Http::response('FAKE-JPEG-BYTES', 200),
        'upload.twitter.com/*' => Http::response(['media_id_string' => '42'], 200),
        'api.twitter.com/*' => Http::response(['data' => ['id' => '999']], 200),
    ]);

    $result = app(CheckStockAlerts::class)->evaluate(alert());

    expect($result['tweeted'])->toBeTrue();
    // The tweet body must reference the uploaded media id.
    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.twitter.com/2/tweets')
        && in_array('42', data_get($req->data(), 'media.media_ids', []), true));
});
