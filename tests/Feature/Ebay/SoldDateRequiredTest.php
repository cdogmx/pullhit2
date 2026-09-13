<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->classifier = app(SoldCompClassifier::class);
    $this->companies = GradingCompany::pluck('id', 'slug')->all();

    $this->card = CatalogItem::factory()->create([
        'item_type' => ItemType::Single,
        'name' => 'Buzz Lightyear - Jungle Ranger',
        'number' => '241',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ])->fresh();
});

/** A candidate as the parser builds one, with or without a sale date. */
function datedCandidate(string $title, int $cents, ?CarbonImmutable $soldAt): SoldCandidate
{
    return new SoldCandidate($title, $cents, $soldAt, (string) random_int(1, 999999999));
}

test('a listing with no sale date is not a sale', function () {
    // eBay pads a thin completed-search page with loosely related ACTIVE
    // listings. They carry no "Sold <date>" caption, and were being stored as
    // sales dated today at the asking price — this one was a Topps card.
    $active = datedCandidate(
        'Topps Now Toy Story 5 SUPER SHORT PRINT TS5SSP Buzz Woody Jessie 2026 RARE+ Base',
        30000,
        null,
    );

    expect($this->classifier->classify($active, $this->card, 500000, $this->companies))->toBeNull();
});

test('the same listing with a sale date is judged on its merits', function () {
    // The date gate must not become a way for anything dated to walk in — it
    // only establishes that a sale happened at all.
    $dated = datedCandidate(
        'Disney Lorcana Buzz Lightyear Jungle Ranger Iconic 241/204',
        750000,
        CarbonImmutable::parse('2026-09-01'),
    );

    $comp = $this->classifier->classify($dated, $this->card, 750000, $this->companies);

    expect($comp)->not->toBeNull()
        ->and($comp->soldAt->toDateString())->toBe('2026-09-01');
});

test('an undated listing is refused even when the title is perfect', function () {
    $perfect = datedCandidate('Disney Lorcana Buzz Lightyear Jungle Ranger Iconic 241/204', 750000, null);

    expect($this->classifier->classify($perfect, $this->card, 750000, $this->companies))->toBeNull();
});

test('a sealed product needs a sale date too', function () {
    $box = CatalogItem::factory()->create([
        'item_type' => ItemType::Sealed,
        'name' => 'Wilds Unknown Booster Box',
        'number' => null,
        'attributes' => ['language' => 'en', 'sealed_type' => 'booster_box'],
    ])->fresh();

    expect($this->classifier->classify(
        datedCandidate('Disney Lorcana Wilds Unknown Booster Box Sealed', 14000, null),
        $box,
        14000,
        $this->companies,
    ))->toBeNull();
});
