<?php

use App\Support\Ebay\EbayBrowseClient;
use App\Support\Ebay\EbayTaxonomy;
use Illuminate\Support\Facades\Http;

function fakeToken(): void
{
    $stub = Mockery::mock(EbayBrowseClient::class);
    $stub->shouldReceive('accessToken')->andReturn('token');
    app()->instance(EbayBrowseClient::class, $stub);
}

test('the US category tree id is the string zero, which is falsy', function () {
    fakeToken();

    Http::fake([
        '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '0']),
        '*get_item_aspects_for_category*' => Http::response(['aspects' => [
            ['localizedAspectName' => 'Card Name', 'aspectValues' => [['localizedValue' => 'Pikachu']]],
            ['localizedAspectName' => 'Set', 'aspectValues' => [
                ['localizedValue' => 'Celebrations'],
                ['localizedValue' => 'SV: Paldean Fates'],
            ]],
        ]]),
    ]);

    // Testing "0" itself: an `if (! $treeId)` here returns an empty vocabulary
    // and every set silently goes unmatched, with nothing logged as wrong.
    expect(app(EbayTaxonomy::class)->aspectValues('Set', '183454'))
        ->toBe(['Celebrations', 'SV: Paldean Fates']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/category_tree/0/'));
});

test('no vocabulary rather than a wrong one when eBay will not say', function () {
    fakeToken();

    Http::fake([
        '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '0']),
        '*get_item_aspects_for_category*' => Http::response([], 500),
    ]);

    expect(app(EbayTaxonomy::class)->aspectValues('Set', '183454'))->toBe([]);
});

test('an aspect the category does not have is empty, not the first one', function () {
    fakeToken();

    Http::fake([
        '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '0']),
        '*get_item_aspects_for_category*' => Http::response(['aspects' => [
            ['localizedAspectName' => 'Card Name', 'aspectValues' => [['localizedValue' => 'Pikachu']]],
        ]]),
    ]);

    expect(app(EbayTaxonomy::class)->aspectValues('Set', '183454'))->toBe([]);
});

test('without a token it asks eBay nothing', function () {
    $stub = Mockery::mock(EbayBrowseClient::class);
    $stub->shouldReceive('accessToken')->andReturn(null);
    app()->instance(EbayBrowseClient::class, $stub);

    Http::fake();

    expect(app(EbayTaxonomy::class)->aspectValues('Set', '183454'))->toBe([]);

    Http::assertNothingSent();
});
