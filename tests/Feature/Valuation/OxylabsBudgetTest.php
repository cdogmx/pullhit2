<?php

use App\Support\Ebay\OxylabsBudgetException;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.oxylabs.username' => 'u',
        'services.oxylabs.password' => 'p',
        'services.oxylabs.endpoint' => 'https://realtime.oxylabs.io/v1/queries',
        'valuation.ebay.daily_cap' => 10,
        'valuation.pricecharting.daily_cap' => 10,
        'valuation.retail.daily_cap' => 10,
    ]);
});

function oxyKey(string $budget): string
{
    return $budget.':daily:'.now()->toDateString();
}

function oxyOk(string $body = '<ul></ul>')
{
    return Http::response(['results' => [['content' => $body]]], 200);
}

test('a delivered result is billed to the named budget only', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxyOk()]);

    app(OxylabsClient::class)->fetchHtml('https://example.com', budget: OxylabsClient::BUDGET_PRICECHARTING);

    expect((int) Cache::get(oxyKey('pricecharting'), 0))->toBe(1)
        ->and((int) Cache::get(oxyKey('ebay'), 0))->toBe(0)
        ->and((int) Cache::get(oxyKey('retail'), 0))->toBe(0);
});

test('a retried fetch bills every delivered result, not just the last one', function () {
    // The bug this metering fixes: a fetch that fails once and then succeeds costs
    // two requests. Oxylabs refunds the non-2xx, so only the success is charged.
    Http::fake(['realtime.oxylabs.io/*' => Http::sequence()
        ->push(['error' => 'boom'], 500)
        ->push(['results' => [['content' => '<ul></ul>']]], 200)]);

    app(OxylabsClient::class)->fetchHtml('https://example.com', budget: OxylabsClient::BUDGET_EBAY);

    expect((int) Cache::get(oxyKey('ebay'), 0))->toBe(1);
    Http::assertSentCount(2);
});

test('two fetches spend two requests — the counter tracks calls, not callers', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxyOk()]);
    $client = app(OxylabsClient::class);

    $client->fetchHtml('https://example.com/a', budget: OxylabsClient::BUDGET_EBAY);
    $client->fetchHtml('https://example.com/b', budget: OxylabsClient::BUDGET_EBAY);

    expect($client->spent(OxylabsClient::BUDGET_EBAY))->toBe(2)
        ->and($client->remaining(OxylabsClient::BUDGET_EBAY))->toBe(8);
});

test('an exhausted budget refuses the fetch without sending a request', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxyOk()]);
    Cache::put(oxyKey('ebay'), 10, now()->addHour());

    expect(fn () => app(OxylabsClient::class)->fetchHtml('https://example.com'))
        ->toThrow(OxylabsBudgetException::class);

    Http::assertNothingSent();
});

test('hasBudget reflects the cap for each budget independently', function () {
    $client = app(OxylabsClient::class);
    Cache::put(oxyKey('ebay'), 10, now()->addHour());

    expect($client->hasBudget(OxylabsClient::BUDGET_EBAY))->toBeFalse()
        ->and($client->hasBudget(OxylabsClient::BUDGET_PRICECHARTING))->toBeTrue()
        ->and($client->remaining(OxylabsClient::BUDGET_EBAY))->toBe(0);
});

test('retail scraping is metered too — it used to be uncounted entirely', function () {
    Http::fake(['realtime.oxylabs.io/*' => oxyOk()]);

    app(OxylabsClient::class)->fetchHtml('https://example.com', budget: OxylabsClient::BUDGET_RETAIL);

    expect((int) Cache::get(oxyKey('retail'), 0))->toBe(1);
});
