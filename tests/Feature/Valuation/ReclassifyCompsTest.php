<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\SaleObservation;

beforeEach(function () {
    $this->psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $this->item = CatalogItem::factory()->create([
        'name' => 'Pikachu ex', 'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'Illustration Rare', 'variant' => 'holo'],
    ]);
});

function misfiled(CatalogItem $item, string $title, int $price = 150000): SaleObservation
{
    // Stored the way the old resolver stored it: a real slab sale, filed raw.
    return SaleObservation::factory()->create([
        'catalog_item_id' => $item->id,
        'condition' => 'NM',
        'grading_company_id' => null,
        'grade' => null,
        'grade_label' => null,
        'price' => $price,
        'is_synthetic' => false,
        'raw' => ['title' => $title, 'source' => 'ebay'],
    ]);
}

test('a dry run writes nothing', function () {
    $o = misfiled($this->item, 'Pikachu ex 276/217 PSA GRADED 10 Holo');

    $this->artisan('valuation:reclassify-comps', ['--card' => $this->item->id, '--dry-run' => true])
        ->assertSuccessful();

    // The whole point of a dry run on a shared production database.
    expect($o->fresh()->grading_company_id)->toBeNull()
        ->and($o->fresh()->condition?->value ?? $o->fresh()->condition)->toBe('NM');
});

test('it moves a misfiled slab out of the raw band', function () {
    $o = misfiled($this->item, 'Pikachu ex 276/217 PSA GRADED 10 Holo');

    $this->artisan('valuation:reclassify-comps', ['--card' => $this->item->id])
        ->assertSuccessful();

    $o->refresh();

    expect($o->grading_company_id)->toBe($this->psa->id)
        ->and((float) $o->grade)->toBe(10.0)
        ->and($o->condition)->toBeNull()
        ->and($o->grade_label)->toBe('PSA 10');
});

test('it leaves a correctly filed comp alone', function () {
    $raw = SaleObservation::factory()->create([
        'catalog_item_id' => $this->item->id,
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 1200, 'is_synthetic' => false,
        'raw' => ['title' => 'Pikachu ex 276/217 Holo NM', 'source' => 'ebay'],
    ]);
    $before = $raw->updated_at;

    $this->artisan('valuation:reclassify-comps', ['--card' => $this->item->id])
        ->assertSuccessful();

    expect($raw->fresh()->grading_company_id)->toBeNull()
        ->and($raw->fresh()->updated_at->eq($before))->toBeTrue();
});

test('it leaves structurally invalid comps for the prune pass', function () {
    // An untracked grader's slab. Re-filing it would only move the problem to
    // another band — it should be deleted, which is prune's job, not this one.
    $o = misfiled($this->item, 'Pikachu ex 276/217 BCCG 10 Graded', 5500);

    $this->artisan('valuation:reclassify-comps', ['--card' => $this->item->id])
        ->expectsOutputToContain('left 1 structurally invalid row(s) to the prune pass')
        ->assertSuccessful();

    expect($o->fresh())->not->toBeNull()
        ->and($o->fresh()->grading_company_id)->toBeNull();
});

test('it recomputes the card so the raw value actually drops', function () {
    // The reason any of this matters: a slab in the raw band sets the raw
    // median, which is also the anchor the price-sanity band is measured
    // against. Moving the slab has to move the value, not just the row.
    misfiled($this->item, 'Pikachu ex 276/217 PSA GRADED 10 Holo', 150000);
    SaleObservation::factory()->create([
        'catalog_item_id' => $this->item->id,
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 1200, 'is_synthetic' => false,
        'raw' => ['title' => 'Pikachu ex 276/217 Holo NM', 'source' => 'ebay'],
    ]);

    $this->artisan('valuation:reclassify-comps', ['--card' => $this->item->id])->assertSuccessful();

    $raw = $this->item->marketValues()->whereNull('grading_company_id')->where('state_key', 'NM')->first();

    // Not exactly 1200: the recompute nets a fee off the observed price, which
    // is why the live card's $12.00 sale reads as $11.64. What matters is that
    // the raw band now follows the $12 card and not the $1,500 slab.
    expect($raw)->not->toBeNull()
        ->and($raw->median)->toBeLessThan(2000)
        ->and($raw->median)->toBeGreaterThan(1000);
});
