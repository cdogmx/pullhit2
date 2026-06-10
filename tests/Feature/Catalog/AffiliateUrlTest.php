<?php

use App\Support\Affiliate\EbayAffiliate;
use App\Support\Affiliate\TcgplayerAffiliate;

test('the eBay affiliate search url carries the campaign id and query', function () {
    config(['services.ebay.campaign_id' => '5338', 'services.ebay.rover_id' => '711-53200-19255-0']);

    $url = app(EbayAffiliate::class)->searchUrl('Reshiram ex 166');

    expect($url)->toContain('campid=5338')
        ->and($url)->toContain('mkevt=1')
        ->and($url)->toContain('Reshiram');
});

test('the eBay search url omits affiliate params when no campaign id', function () {
    config(['services.ebay.campaign_id' => null]);

    expect(app(EbayAffiliate::class)->searchUrl('Reshiram'))->not->toContain('campid');
});

test('the TCGplayer url carries the partner id when configured', function () {
    config(['services.tcgplayer.partner' => 'p2h']);

    expect(app(TcgplayerAffiliate::class)->searchUrl('Reshiram ex'))->toContain('partner=p2h');
});
