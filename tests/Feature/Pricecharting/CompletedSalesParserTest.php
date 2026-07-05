<?php

use App\Enums\Venue;
use App\Support\Pricecharting\CompletedSalesParser;

function pcFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/pricecharting-completed-sales.html'));
}

test('it parses completed sales from the used tab with source, date, price and id', function () {
    $sales = (new CompletedSalesParser)->parse(pcFixture(), 'used');

    expect($sales)->toHaveCount(2);

    // eBay row.
    expect($sales[0]->source)->toBe(Venue::Ebay)
        ->and($sales[0]->listingId)->toBe('137475278230')
        ->and($sales[0]->priceCents)->toBe(15500)
        ->and($sales[0]->soldAt->toDateString())->toBe('2026-07-05')
        ->and($sales[0]->title)->toContain('Elite Trainer Box')
        ->and($sales[0]->url)->toContain('ebay.com/itm/137475278230');

    // TCGplayer row — comma-formatted price parses, source tagged.
    expect($sales[1]->source)->toBe(Venue::TCGplayer)
        ->and($sales[1]->listingId)->toBe('668496')
        ->and($sales[1]->priceCents)->toBe(117564)
        ->and($sales[1]->soldAt->toDateString())->toBe('2026-06-14');
});

test('it only reads the requested tab (grades do not bleed into ungraded)', function () {
    $used = (new CompletedSalesParser)->parse(pcFixture(), 'used');
    $psa10 = (new CompletedSalesParser)->parse(pcFixture(), 'manual-only');

    // The PSA 10 sale ($400) must not appear in the ungraded/used tab.
    expect(collect($used)->pluck('priceCents'))->not->toContain(40000)
        ->and($psa10)->toHaveCount(1)
        ->and($psa10[0]->priceCents)->toBe(40000);
});

test('it returns nothing when the tab is absent', function () {
    expect((new CompletedSalesParser)->parse('<div>no sales here</div>', 'used'))->toBe([]);
});
