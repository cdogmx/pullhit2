<?php

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Models\CatalogItem;
use App\Support\Ebay\EbayDisabledException;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('valuation.ebay.enabled', false);
    config()->set('valuation.ebay.daily_cap', 1000);
    config()->set('services.oxylabs', [
        'username' => 'u', 'password' => 'p',
        'endpoint' => 'https://realtime.oxylabs.io/v1/queries',
    ]);

    Http::fake(['realtime.oxylabs.io/*' => Http::response([
        'results' => [['content' => '<html></html>', 'status_code' => 200]],
    ])]);
});

test('the switch is enforced at the meter, not at the callers', function () {
    // Two commands (catalog:refresh-ebay, valuation:resweep-misses) never check
    // the flag, so a caller-side switch is only as good as the next caller
    // written. Nothing reaches eBay without passing through here.
    expect(fn () => app(OxylabsClient::class)->fetchHtml(
        'https://www.ebay.com/sch/i.html?LH_Sold=1',
        budget: OxylabsClient::BUDGET_EBAY,
    ))->toThrow(EbayDisabledException::class);

    Http::assertNothingSent();
});

test('nothing is billed for a request that is never made', function () {
    $client = app(OxylabsClient::class);

    try {
        $client->fetchHtml('https://www.ebay.com/sch/i.html', budget: OxylabsClient::BUDGET_EBAY);
    } catch (EbayDisabledException) {
        // expected
    }

    expect($client->spent(OxylabsClient::BUDGET_EBAY))->toBe(0);
});

test('the sold source refuses without touching the breaker', function () {
    $source = app(EbaySoldSource::class);
    $item = CatalogItem::factory()->create();

    expect(fn () => $source->fetch($item))->toThrow(EbayDisabledException::class);

    // Being switched off is not a gate, and must not leave strikes behind for
    // the next run to inherit once eBay is switched back on.
    expect($source->consecutiveBlocks())->toBe(0)
        ->and($source->isDown())->toBeFalse();

    Http::assertNothingSent();
});

test('a switched-off fetch is handled like any other block, not as a crash', function () {
    // EbayDisabledException extends EbayBlockedException so the callers that
    // already catch "we could not read eBay" keep working — otherwise this
    // surfaces as a 500 on a card page.
    $item = CatalogItem::factory()->create();
    $before = $item->ebay_refreshed_at;

    $ingested = app(IngestEbaySoldComps::class)($item);

    expect($ingested)->toBe(0)
        // Crucially it must NOT stamp the card as freshly checked.
        ->and($item->fresh()->ebay_refreshed_at)->toEqual($before);
});

test('the other budgets are untouched — this switch is about eBay only', function () {
    // PriceCharting still works and is still wanted; turning eBay off must not
    // take the rest of the valuation pipeline with it.
    $html = app(OxylabsClient::class)->fetchHtml(
        'https://www.pricecharting.com/game/pokemon-base-set/charizard-4',
        render: false,
        budget: OxylabsClient::BUDGET_PRICECHARTING,
    );

    expect($html)->toBe('<html></html>');

    Http::assertSentCount(1);
});

test('switching it back on needs nothing but the flag', function () {
    config()->set('valuation.ebay.enabled', true);

    $html = app(OxylabsClient::class)->fetchHtml(
        'https://www.ebay.com/sch/i.html',
        budget: OxylabsClient::BUDGET_EBAY,
    );

    expect($html)->toBe('<html></html>');
});

test('the sweep command stops before it claims any work', function () {
    $this->artisan('valuation:sweep-ebay')->assertSuccessful();

    Http::assertNothingSent();
});

test('the sealed sweep stops too', function () {
    $this->artisan('valuation:sweep-sealed')->assertSuccessful();

    Http::assertNothingSent();
});

test('the status probe declines to spend while eBay is off', function () {
    $this->artisan('ebay:sold-status', ['--probe' => true])
        ->expectsOutputToContain('eBay fetching is switched off')
        ->assertSuccessful();

    Http::assertNothingSent();
});
