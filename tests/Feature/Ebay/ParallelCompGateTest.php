<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;

/**
 * A collectible's parallel IS its printing, and the prices are not close.
 *
 * Mickey #50 in 2026 Topps Chrome Disney is $9.68 as a base card and $436 as an
 * Aqua Wave. Thirty-two printings share a name and a number, so without a gate
 * whichever row the sweep tries first absorbs every sale of all of them — which
 * is what happened: 37 sales of six printings landed on one row.
 */
beforeEach(function () {
    $vertical = Vertical::factory()->create(['slug' => 'collectibles', 'name' => 'Collectibles']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'topps']);
    $this->set = Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'chrome-disney', 'language' => 'en']);

    $this->printing = function (?string $parallel) use ($vertical, $line) {
        return CatalogItem::factory()->create([
            'vertical_id' => $vertical->id,
            'product_line_id' => $line->id,
            'set_id' => $this->set->id,
            'name' => 'Mickey Mouse',
            'number' => '50',
            'attributes' => array_filter(['language' => 'en', 'parallel' => $parallel]),
        ]);
    };

    // The vocabulary the gate reads out of the set.
    foreach ([null, 'Refractor', 'Aqua Wave', 'Aqua Refractor', 'Gold Refractor',
        'Prism Refractor', 'X-Fractor', 'Raywave', 'Aqua Mini Diamond'] as $p) {
        ($this->printing)($p);
    }

    $this->companies = ['psa' => GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA'])->id];
});

function sale(string $title, int $cents): SoldCandidate
{
    return new SoldCandidate($title, $cents, CarbonImmutable::now()->subDays(3), 'L'.$cents);
}

function gate(Set $set, ?string $parallel): CatalogItem
{
    return CatalogItem::where('set_id', $set->id)->get()
        ->first(fn ($c) => ($c->attributes['parallel'] ?? null) === $parallel);
}

test('a base card rejects every listing that names a parallel', function () {
    $base = gate($this->set, null);
    $classifier = new SoldCompClassifier;

    foreach (['Gold Refractor', 'Aqua Wave Refractor', 'Prism Refractor', 'X-Fractor'] as $named) {
        expect($classifier->classify(sale("2026 Topps Chrome Disney Mickey Mouse #50 {$named}", 4000), $base, 1000, $this->companies))
            ->toBeNull("base absorbed a {$named} listing");
    }

    // …but still takes a plain base sale, which is most of them.
    expect($classifier->classify(sale('2026 Topps Chrome Disney Mickey Mouse #50 Base Set', 1000), $base, 1000, $this->companies))
        ->not->toBeNull();
});

test('a parallel requires its own name and rejects a different one', function () {
    $gold = gate($this->set, 'Gold Refractor');
    $classifier = new SoldCompClassifier;

    expect($classifier->classify(sale('2026 Topps Chrome Disney Mickey Mouse #50 Gold Refractor', 4000), $gold, 4000, $this->companies))
        ->not->toBeNull()
        ->and($classifier->classify(sale('2026 Topps Chrome Disney Mickey Mouse #50 Prism Refractor', 4800), $gold, 4000, $this->companies))
        ->toBeNull()
        ->and($classifier->classify(sale('2026 Topps Chrome Disney Mickey Mouse #50', 1000), $gold, 4000, $this->companies))
        ->toBeNull();
});

test('a specific parallel beats the generic tail it ends with', function () {
    $classifier = new SoldCompClassifier;

    // "Aqua Wave Refractor" is an Aqua Wave, not a Refractor — the sellers write
    // "Refractor" after almost everything.
    expect($classifier->classify(sale('2026 Topps Chrome Disney Mickey Mouse #50 Aqua Wave Refractor 148/199', 43600), gate($this->set, 'Aqua Wave'), 43600, $this->companies))
        ->not->toBeNull()
        ->and($classifier->classify(sale('2026 Topps Chrome Disney Mickey Mouse #50 Aqua Wave Refractor 148/199', 43600), gate($this->set, 'Refractor'), 4000, $this->companies))
        ->toBeNull();
});

test('colour and finish are not interchangeable', function () {
    $classifier = new SoldCompClassifier;

    // Three aqua printings at three prices. The word "aqua" alone must not
    // decide which one sold.
    expect($classifier->classify(sale('Mickey Mouse #50 Aqua Mini Diamond', 6000), gate($this->set, 'Aqua Wave'), 43600, $this->companies))
        ->toBeNull()
        ->and($classifier->classify(sale('Mickey Mouse #50 Aqua Refractor /199', 20000), gate($this->set, 'Aqua Mini Diamond'), 6000, $this->companies))
        ->toBeNull()
        ->and($classifier->classify(sale('Mickey Mouse #50 Aqua Mini Diamond', 6000), gate($this->set, 'Aqua Mini Diamond'), 6000, $this->companies))
        ->not->toBeNull();
});

test('it reads the spellings sellers actually use', function () {
    $classifier = new SoldCompClassifier;

    // "X-Factor" is a near-universal typo for X-Fractor, and RayWave is written
    // as two words as often as one.
    expect($classifier->classify(sale('2026 Topps Chrome Disney MICKEY MOUSE X-Factor Refractor #50', 3700), gate($this->set, 'X-Fractor'), 3700, $this->companies))
        ->not->toBeNull()
        ->and($classifier->classify(sale('Mickey Mouse #50 Ray Wave Refractor', 3200), gate($this->set, 'Raywave'), 3200, $this->companies))
        ->not->toBeNull();
});

test('the gate leaves the trading-card verticals alone', function () {
    // Pokemon has no `parallel` facet; the gate must not reject on its absence.
    $tcg = Vertical::factory()->create(['slug' => 'tcg', 'name' => 'TCG']);
    $line = ProductLine::factory()->create(['vertical_id' => $tcg->id, 'slug' => 'pokemon']);
    $set = Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'celebrations', 'language' => 'en']);

    $card = CatalogItem::factory()->create([
        'vertical_id' => $tcg->id, 'product_line_id' => $line->id, 'set_id' => $set->id,
        'name' => 'Flying Pikachu V', 'number' => '6',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    expect((new SoldCompClassifier)->classify(sale('Flying Pikachu V 6 Celebrations Holo', 500), $card, 500, $this->companies))
        ->not->toBeNull();
});
