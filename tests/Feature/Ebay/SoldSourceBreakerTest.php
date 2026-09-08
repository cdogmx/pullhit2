<?php

use App\Models\CatalogItem;
use App\Support\Ebay\EbayBlockedException;
use App\Support\Ebay\EbaySoldSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('valuation.ebay.fetch_attempts', 2);
    config()->set('valuation.ebay.breaker.threshold', 3);
    config()->set('valuation.ebay.breaker.cooldown_minutes', 60);
    config()->set('services.oxylabs', [
        'username' => 'u', 'password' => 'p',
        'endpoint' => 'https://realtime.oxylabs.io/v1/queries',
    ]);
    config()->set('valuation.ebay.daily_cap', 100000);

    $this->item = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4']);
});

/** An Oxylabs reply carrying `$content` as the delivered page. */
function oxylabsReturns(string $content): Closure
{
    return fn () => Http::response(['results' => [['content' => $content, 'status_code' => 200]]]);
}

/** What a gated sold search actually delivers: a successful reply, no page. */
function oxylabsGated(): Closure
{
    return oxylabsReturns('');
}

test('a gated sold search trips the breaker after the threshold', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxylabsGated()]);

    $source = app(EbaySoldSource::class);

    // Each fetch burns `fetch_attempts` requests before it reports a block.
    foreach (range(1, 3) as $round) {
        expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class);
    }

    expect($source->isDown())->toBeTrue();

    Http::assertSentCount(6);

    // The next call must cost nothing at all — this is the whole point.
    expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class);

    Http::assertSentCount(6);
});

test('it keeps paying until the threshold, so one bad page does not stop the sweep', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxylabsGated()]);

    $source = app(EbaySoldSource::class);

    expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class)
        ->and($source->isDown())->toBeFalse()
        ->and($source->consecutiveBlocks())->toBe(1);
});

test('a readable page closes the breaker and clears the strikes', function () {
    $html = file_get_contents(base_path('tests/Fixtures/ebay-sold-search.html'));

    Http::fake(['realtime.oxylabs.io/*' => Http::sequence()
        ->push(['results' => [['content' => '', 'status_code' => 200]]])
        ->push(['results' => [['content' => '', 'status_code' => 200]]])
        ->whenEmpty(Http::response(['results' => [['content' => $html, 'status_code' => 200]]])),
    ]);

    $source = app(EbaySoldSource::class);

    expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class)
        ->and($source->consecutiveBlocks())->toBe(1);

    // eBay lets us through again.
    expect($source->fetch($this->item))->not->toBeEmpty()
        ->and($source->consecutiveBlocks())->toBe(0)
        ->and($source->isDown())->toBeFalse();
});

test('an explicit "no matches" page is a real zero, not a block', function () {
    // eBay saying it found nothing is an answer. Counting it as a gate would
    // trip the breaker on genuinely obscure cards and stop the whole sweep.
    Http::fake(['realtime.oxylabs.io/*' => oxylabsReturns(
        '<html><body><h3 class="srp-save-null-search__heading">No exact matches found</h3></body></html>'
    )]);

    $source = app(EbaySoldSource::class);

    foreach (range(1, 5) as $round) {
        expect($source->fetch($this->item))->toBe([]);
    }

    expect($source->isDown())->toBeFalse()
        ->and($source->consecutiveBlocks())->toBe(0);
});

test('the breaker reopens once the cooldown lapses', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxylabsGated()]);

    $source = app(EbaySoldSource::class);

    foreach (range(1, 3) as $round) {
        expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class);
    }

    expect($source->isDown())->toBeTrue();

    $this->travel(61)->minutes();

    expect($source->isDown())->toBeFalse();

    // It probes rather than staying down forever, so it heals when eBay relents.
    expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class);

    Http::assertSentCount(8);
});

test('a gate still up on the probe trips again at once, not after another threshold', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxylabsGated()]);

    $source = app(EbaySoldSource::class);

    foreach (range(1, 3) as $round) {
        expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class);
    }

    $this->travel(61)->minutes();

    expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class)
        // Strikes outlive the cooldown, so one failed probe is enough.
        ->and($source->isDown())->toBeTrue();
});

test('reset forces a retry before the cooldown is up', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxylabsGated()]);

    $source = app(EbaySoldSource::class);

    foreach (range(1, 3) as $round) {
        expect(fn () => $source->fetch($this->item))->toThrow(EbayBlockedException::class);
    }

    expect($source->isDown())->toBeTrue();

    $source->reset();

    expect($source->isDown())->toBeFalse()
        ->and($source->consecutiveBlocks())->toBe(0);
});
