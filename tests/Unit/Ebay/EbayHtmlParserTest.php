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
<li class="s-card s-card--horizontal" data-listingid="287379458210"><div class="su-card-container__content"><div class="su-card-container__header"><div class="s-card__caption"><span class="su-styled-text positive default" aria-label="Sold Item">Sold  Jun 11, 2026</span></div><a class="s-card__link" target="_blank" tabindex="-1" href="https://www.ebay.com/itm/287379458210?_skw=x&amp;hash=item42e92688a2"><div role="heading" aria-level="3" class="s-card__title"><span class="su-styled-text primary default">Keldeo EX 167/086 Special Illustration Rare SIR Pokemon SV White Flare Near Mint</span><span class="clipped">Opens in a new window or tab</span></div></a></div><div class="su-card-container__attributes"><div class="s-card__attribute-row"><span class="su-styled-text positive bold large-1 s-card__price">$84.59</span></div><div class="su-card-container__attributes__secondary"><div class="s-card__attribute-row"><span class="su-styled-text primary large">vozavu-live </span><span class="su-styled-text primary large">100% positive (159)</span></div></div></div></div></li>
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

const EBAY_WITH_IMAGE = <<<'HTML'
<li class="s-card"><a class="s-card__link" href="https://www.ebay.com/itm/287379458210"><div class="s-card__image-wrapper"><img class="s-card__image" src="https://i.ebayimg.com/images/g/AbCdEf/s-l140.jpg" alt=""></div></a><a class="s-card__link" href="https://www.ebay.com/itm/287379458210"><div class="s-card__title"><span class="su-styled-text">Charizard ex 199/165 PSA 10 SV 151</span></div></a><span class="s-card__price">$420.00</span><span>Sold  Jun 9, 2026</span></li>
HTML;

test('it extracts the gallery image and upsizes the thumbnail', function () {
    $cands = EbayHtmlParser::parse(EBAY_WITH_IMAGE);

    expect($cands)->toHaveCount(1)
        ->and($cands[0]->imageUrl)->toBe('https://i.ebayimg.com/images/g/AbCdEf/s-l500.jpg');
});

test('a listing with no image yields a null imageUrl', function () {
    $cands = EbayHtmlParser::parse(EBAY_REAL_CARD);

    expect($cands[0]->imageUrl)->toBeNull();
});

test('it parses live (quoted) s-card markup and extracts the seller', function () {
    $cands = EbayHtmlParser::parse(EBAY_REAL_CARD);

    expect($cands)->toHaveCount(1);
    expect($cands[0]->title)->toBe(
        'Keldeo EX 167/086 Special Illustration Rare SIR Pokemon SV White Flare Near Mint',
    )
        ->and($cands[0]->priceCents)->toBe(8459)
        ->and($cands[0]->itemId)->toBe('287379458210')
        ->and($cands[0]->seller)->toBe('vozavu-live')
        // The sold-date caption precedes the title link; the <li>-anchored
        // parser still attributes it to this card.
        ->and($cands[0]->soldAt?->toDateString())->toBe('2026-06-11');
});

// eBay serves its LEGACY "s-item" layout interchangeably with the current
// s-card one — the price nests inside an <span class=ITALIC>, and the seller
// lives in an s-item__seller-info-text span ("name (feedback) 100%").
const EBAY_LEGACY_ITEM = <<<'HTML'
<li class="s-item s-item__pl-on-bottom"><div class="s-item__image-section"><div class="s-item__image"><a class="s-item__link" href="https://www.ebay.com/itm/226789012345?hash=item42"><div class="s-item__image-wrapper"><img src="https://i.ebayimg.com/images/g/AbC/s-l225.jpg"></div></a></div></div><div class="s-item__info clearfix"><a class="s-item__link" href="https://www.ebay.com/itm/226789012345?hash=item42"><div class="s-item__title"><span role="heading" aria-level="3">Pokemon TCG Scarlet &amp; Violet Black Bolt Booster Bundle Sealed</span></div></a><div class="s-item__subtitle"><span class="SECONDARY_INFO">Brand New</span></div><span class="s-item__price"><span class="ITALIC">$69.99</span></span><div class="s-item__caption"><span class="s-item__caption--signal POSITIVE"><span>Sold  Jul 14, 2026</span></span></div><span class="s-item__seller-info-text">nyrk2014 (5,500) 100%</span></div></li>
HTML;

test('it parses the legacy s-item layout (nested price, seller-info span)', function () {
    $cands = EbayHtmlParser::parse(EBAY_LEGACY_ITEM);

    expect($cands)->toHaveCount(1);
    expect($cands[0]->title)->toBe('Pokemon TCG Scarlet & Violet Black Bolt Booster Bundle Sealed')
        ->and($cands[0]->priceCents)->toBe(6999)
        ->and($cands[0]->itemId)->toBe('226789012345')
        ->and($cands[0]->seller)->toBe('nyrk2014')
        ->and($cands[0]->soldAt?->toDateString())->toBe('2026-07-14')
        ->and($cands[0]->imageUrl)->toBe('https://i.ebayimg.com/images/g/AbC/s-l500.jpg');
});

test('isEmptyResults detects only an explicit zero-match page', function () {
    expect(EbayHtmlParser::isEmptyResults('<h1>0 results</h1>'))->toBeTrue()
        ->and(EbayHtmlParser::isEmptyResults('No exact matches found'))->toBeTrue()
        // A degraded render (srp shell, no cards, no message) is NOT a real zero.
        ->and(EbayHtmlParser::isEmptyResults('<div class="srp-river-results"></div>'))->toBeFalse()
        ->and(EbayHtmlParser::isEmptyResults('<html><body>Pardon Our Interruption</body></html>'))->toBeFalse()
        ->and(EbayHtmlParser::isEmptyResults(''))->toBeFalse();
});

test('it parses the live sold-search layout', function () {
    // Captured from a real sold search. eBay moved `s-card__link` out of first
    // place in the class list and pulled the sold-date caption INSIDE the
    // anchor; the previous matcher required the title to follow the <a>
    // directly, so it read every sold page as empty — which looked like a block.
    // Unit tests run without the app container, so resolve the path directly.
    $cands = EbayHtmlParser::parse(file_get_contents(dirname(__DIR__, 2).'/Fixtures/ebay-sold-search.html'));

    expect($cands)->toHaveCount(2); // the "Shop on eBay" promo is still skipped

    expect($cands[0]->title)->toBe('Pokemon Obsidian Flames Reverse Holos Choose Your Card! Ultra Rares Near Mint')
        ->and($cands[0]->priceCents)->toBe(200)
        ->and($cands[0]->soldAt?->toDateString())->toBe('2026-07-30');

    expect($cands[1]->priceCents)->toBe(70000)
        ->and($cands[1]->soldAt?->toDateString())->toBe('2026-07-29');

    // Every comp must carry a real listing id, never the promo placeholder.
    foreach ($cands as $c) {
        expect($c->itemId)->not->toBe('123456')
            ->and($c->url)->toStartWith('https://www.ebay.com/itm/');
    }
});
