<?php

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Support\Ebay\SoldCandidate;
use Carbon\CarbonImmutable;

/**
 * The first comps on a card nobody has priced yet.
 *
 * bandOk() returns true when there is no anchor, because a card with no value
 * has to be able to get one. That is also how a card gets stuck: one wrong
 * listing is accepted unchallenged, becomes the median, and then defines the
 * band that judges everything after it — which is exactly what happened to
 * Celebrations Flying Pikachu V.
 *
 * There is something to judge against even then. Every candidate for a card
 * arrives in the same batch, so the batch's own raw median is a seed: far from
 * authoritative, but enough to notice the listing that is twenty times the rest.
 */
beforeEach(function () {
    $this->item = CatalogItem::factory()->create([
        'name' => 'Pikachu ex', 'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'Illustration Rare', 'variant' => 'holo'],
    ]);
    GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    // No market value and no PriceCharting row: genuinely unanchored.
});

function rawCandidates(array $cents): array
{
    $i = 0;

    return array_map(fn ($c) => new SoldCandidate(
        'Pikachu ex 276/217 SIR Ascended Heroes',
        $c,
        CarbonImmutable::now()->subDays(3),
        'listing'.(++$i),
    ), $cents);
}

test('a wild outlier is rejected on a card with no value yet', function () {
    // Eight sales around $10 and one at $400. Before, all nine were accepted
    // because there was nothing to measure them against.
    $candidates = rawCandidates([900, 1000, 1000, 1100, 1050, 950, 1200, 1000, 40000]);

    $count = app(IngestEbaySoldComps::class)->ingest($this->item, $candidates);

    expect($count)->toBe(8)
        ->and($this->item->saleObservations()->max('price'))->toBeLessThan(40000);
});

test('a thin batch is still accepted whole', function () {
    // Three candidates have no meaningful median, and refusing them would stop
    // a quiet card ever getting a first price at all. Being permissive here is
    // the deliberate trade.
    $count = app(IngestEbaySoldComps::class)->ingest($this->item, rawCandidates([900, 1000, 40000]));

    expect($count)->toBe(3);
});

test('a genuine spread is not trimmed to its middle', function () {
    // Condition alone moves a card several-fold, so the seed has to be loose
    // enough to keep an honest range. Only the absurd goes.
    $count = app(IngestEbaySoldComps::class)->ingest(
        $this->item,
        rawCandidates([500, 800, 1000, 1200, 1500, 2000, 2500, 3000]),
    );

    expect($count)->toBe(8);
});

test('graded listings do not inflate the seed', function () {
    // A PSA 10 sells for many times raw. Seeding from every candidate would
    // lift the band and let the expensive raw outlier back in.
    $candidates = rawCandidates([900, 1000, 1000, 1100, 1050, 950, 40000]);
    $candidates[] = new SoldCandidate('Pikachu ex 276/217 PSA 10 Ascended Heroes', 380000, CarbonImmutable::now(), 'slab1');
    $candidates[] = new SoldCandidate('Pikachu ex 276/217 PSA 10 SIR', 390000, CarbonImmutable::now(), 'slab2');

    app(IngestEbaySoldComps::class)->ingest($this->item, $candidates);

    // Both slabs kept (the band never applied to them), the raw outlier gone.
    expect($this->item->saleObservations()->whereNotNull('grading_company_id')->count())->toBe(2)
        ->and($this->item->saleObservations()->whereNull('grading_company_id')->max('price'))->toBeLessThan(40000);
});
