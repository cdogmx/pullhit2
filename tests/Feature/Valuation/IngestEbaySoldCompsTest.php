<?php

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\SaleObservation;
use Illuminate\Support\Facades\Http;

const OXY_HTML = <<<'HTML'
<ul>
<li><a class=s-card__link href=https://ebay.com/itm/123456><div class=s-card__title><span>Shop on eBay</span></div></a><span class="s-card__price">$20.00</span></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222333><div class=s-card__title><span>Pikachu ex 276/217 SIR Ascended Heroes</span></div></a><span class="s-card__price">$1,290.00</span><span>Sold  Jun 1, 2026</span></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222334><div class=s-card__title><span>Pikachu ex 276/217 PSA 10 Ascended Heroes</span></div></a><span class="s-card__price">$3,800.00</span><span>Sold  Jun 3, 2026</span></li>
<li><a class=s-card__link href=https://www.ebay.com/itm/355111222335><div class=s-card__title><span>MYSTERY BOX chance at Pikachu ex 276/217</span></div></a><span class="s-card__price">$9.99</span><span>Sold  Jun 4, 2026</span></li>
</ul>
HTML;

beforeEach(function () {
    $this->item = CatalogItem::factory()->create([
        'name' => 'Pikachu ex', 'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'Illustration Rare', 'variant' => 'holo'],
    ]);
    GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    // A synthetic placeholder value (the anchor) to be replaced.
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => Condition::NearMint,
        'median' => 130_000, 'is_estimated' => true,
    ]);
    SaleObservation::factory()->count(3)->for($this->item)->create([
        'condition' => Condition::NearMint, 'is_synthetic' => true,
    ]);
});

test('it ingests genuine eBay comps, drops synthetic, and de-estimates the value', function () {
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => OXY_HTML]]], 200),
    ]);

    $count = app(IngestEbaySoldComps::class)($this->item);

    expect($count)->toBe(2); // SIR raw + PSA 10 (mystery + promo rejected)

    expect(SaleObservation::where('catalog_item_id', $this->item->id)->where('venue', 'ebay')->count())->toBe(2);
    expect(SaleObservation::where('catalog_item_id', $this->item->id)->where('is_synthetic', true)->count())->toBe(0);
    expect($this->item->fresh()->ebay_refreshed_at)->not->toBeNull();

    $nm = MarketValue::where('catalog_item_id', $this->item->id)->where('state_key', 'NM')->first();
    expect($nm->is_estimated)->toBeFalse();

    expect(MarketValue::where('catalog_item_id', $this->item->id)->where('state_key', 'psa-10')->exists())->toBeTrue();
});

test('it keeps the synthetic placeholder when no genuine comps are found', function () {
    // A real empty search still returns a full results page (srp markers + a
    // "no matches" message) — a legitimate zero, so we DO stamp refreshed_at.
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => '<div class="srp-river-results"><h3>0 results found. No exact matches found.</h3></div>']]], 200),
    ]);

    $count = app(IngestEbaySoldComps::class)($this->item);

    expect($count)->toBe(0);
    expect(SaleObservation::where('catalog_item_id', $this->item->id)->where('is_synthetic', true)->count())->toBe(3);
    expect($this->item->fresh()->ebay_refreshed_at)->not->toBeNull();
});

test('it retries a degraded render (results shell, no cards) and then ingests', function () {
    // Retries are opt-in now — the default is a single attempt, because eBay's
    // sold view answers with a sign-in wall that a retry only re-buys. The
    // mechanism still works when a target is worth paying to re-fetch.
    config(['valuation.ebay.fetch_attempts' => 3]);

    // First fetch: a valid SRP shell with no rendered listings and no
    // "no matches" message (a degraded render). Second: the real page.
    Http::fake([
        'realtime.oxylabs.io/*' => Http::sequence()
            ->push(['results' => [['content' => '<div class="srp-river-results"></div>']]], 200)
            ->push(['results' => [['content' => OXY_HTML]]], 200),
    ]);

    $count = app(IngestEbaySoldComps::class)($this->item);

    expect($count)->toBe(2);
    expect($this->item->fresh()->ebay_refreshed_at)->not->toBeNull();
});

test('an anti-bot page is not cached as empty — it keeps synthetic and does not stamp refreshed_at', function () {
    // No srp markers, no listings → an interstitial/captcha, not a real zero.
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => '<html><body>Pardon Our Interruption... please verify.</body></html>']]], 200),
    ]);

    $count = app(IngestEbaySoldComps::class)($this->item);

    expect($count)->toBe(0);
    expect(SaleObservation::where('catalog_item_id', $this->item->id)->where('is_synthetic', true)->count())->toBe(3);
    // Left null so the next view retries instead of holding a false "no comps".
    expect($this->item->fresh()->ebay_refreshed_at)->toBeNull();
});
