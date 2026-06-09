<?php

use App\Support\Ebay\EbayHtmlParser;

const EBAY_FIXTURE = <<<'HTML'
<ul class="srp-results">
<li><a class=s-card__link href=https://ebay.com/itm/123456?x=1><div role=heading class=s-card__title><span class="su-styled-text primary default">Shop on eBay</span></div></a><div class=s-card__price-wrap><span class="su-styled-text primary bold s-card__price">$20.00</span></div></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222333?hash=abc><div class=s-card__title><span class="su-styled-text">New Listing</span><span class="su-styled-text primary default">Pikachu ex 276/217 SIR Ascended Heroes</span></div></a><span class="su-styled-text bold s-card__price">$1,290.00</span><span class="su-styled-text">Sold  Jun 1, 2026</span></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222334><div class=s-card__title><span class="su-styled-text">2026 Pikachu ex 276/217 PSA 10 Ascended Heroes</span></div></a><span class="s-card__price">$3,800.00</span><span>Sold  Jun 3, 2026</span></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222335><div class=s-card__title><span class="su-styled-text">MYSTERY BOX chance at Pikachu ex 276/217</span></div></a><span class="s-card__price">$9.99</span><span>Sold  Jun 4, 2026</span></li>
</ul>
HTML;

test('it parses listings, skips the promo card, and strips the New Listing label', function () {
    $cands = EbayHtmlParser::parse(EBAY_FIXTURE);

    expect($cands)->toHaveCount(3); // "Shop on eBay" promo skipped

    expect($cands[0]->title)->toBe('Pikachu ex 276/217 SIR Ascended Heroes')
        ->and($cands[0]->priceCents)->toBe(129000)
        ->and($cands[0]->itemId)->toBe('355111222333')
        ->and($cands[0]->soldAt?->toDateString())->toBe('2026-06-01');

    expect($cands[1]->title)->toContain('PSA 10')
        ->and($cands[1]->priceCents)->toBe(380000);
});
