<?php

use App\Support\Ebay\EbayHtmlParser;

const EBAY_FIXTURE = <<<'HTML'
<ul class="srp-results">
<li><a class=s-card__link href=https://ebay.com/itm/123456?x=1><div role=heading class=s-card__title><span class="su-styled-text primary default">Shop on eBay</span></div></a><div class=s-card__price-wrap><span class="su-styled-text primary bold s-card__price">$20.00</span></div></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222333?hash=abc><div class=s-card__title><span class="su-styled-text">New Listing</span><span class="su-styled-text primary default">Pikachu ex 276/217 SIR Ascended Heroes</span></div></a><span class="su-styled-text bold s-card__price">$1,290.00</span><span class="su-styled-text">Sold  Jun 1, 2026</span><span class="su-styled-text primary large">cardshark99 </span><span class="su-styled-text primary large">99.8% positive (4,210)</span></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222334><div class=s-card__title><span class="su-styled-text">2026 Pikachu ex 276/217 PSA 10 Ascended Heroes</span></div></a><span class="s-card__price">$3,800.00</span><span>Sold  Jun 3, 2026</span></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222335><div class=s-card__title><span class="su-styled-text">MYSTERY BOX chance at Pikachu ex 276/217</span></div></a><span class="s-card__price">$9.99</span><span>Sold  Jun 4, 2026</span></li>
</ul>
HTML;

// Real eBay "s-card" markup (quoted attributes, seller in the secondary
// attributes block) — the live shape, captured from a sold-search result.
const EBAY_REAL_CARD = <<<'HTML'
<li class="s-card s-card--horizontal" data-listingid="287379458210"><div class="su-card-container__content"><div class="su-card-container__header"><a class="s-card__link" target="_blank" tabindex="-1" href="https://www.ebay.com/itm/287379458210?_skw=x&amp;hash=item42e92688a2"><div role="heading" aria-level="3" class="s-card__title"><span class="su-styled-text primary default">Keldeo EX 167/086 Special Illustration Rare SIR Pokemon SV White Flare Near Mint</span><span class="clipped">Opens in a new window or tab</span></div></a></div><div class="su-card-container__attributes"><div class="s-card__attribute-row"><span class="su-styled-text positive bold large-1 s-card__price">$84.59</span></div><div class="su-card-container__attributes__secondary"><div class="s-card__attribute-row"><span class="su-styled-text primary large">vozavu-live </span><span class="su-styled-text primary large">100% positive (159)</span></div></div></div></div></li>
HTML;

test('it parses listings, skips the promo card, and strips the New Listing label', function () {
    $cands = EbayHtmlParser::parse(EBAY_FIXTURE);

    expect($cands)->toHaveCount(3); // "Shop on eBay" promo skipped

    expect($cands[0]->title)->toBe('Pikachu ex 276/217 SIR Ascended Heroes')
        ->and($cands[0]->priceCents)->toBe(129000)
        ->and($cands[0]->itemId)->toBe('355111222333')
        ->and($cands[0]->soldAt?->toDateString())->toBe('2026-06-01')
        ->and($cands[0]->seller)->toBe('cardshark99');

    expect($cands[1]->title)->toContain('PSA 10')
        ->and($cands[1]->priceCents)->toBe(380000)
        ->and($cands[1]->seller)->toBeNull(); // no seller block on this card

    // Each candidate carries a clean, tracking-free listing URL.
    expect($cands[0]->url)->toBe('https://www.ebay.com/itm/355111222333');
});

test('it parses live (quoted) s-card markup and extracts the seller', function () {
    $cands = EbayHtmlParser::parse(EBAY_REAL_CARD);

    expect($cands)->toHaveCount(1);
    expect($cands[0]->title)->toBe(
        'Keldeo EX 167/086 Special Illustration Rare SIR Pokemon SV White Flare Near Mint',
    )
        ->and($cands[0]->priceCents)->toBe(8459)
        ->and($cands[0]->itemId)->toBe('287379458210')
        ->and($cands[0]->seller)->toBe('vozavu-live');
});
