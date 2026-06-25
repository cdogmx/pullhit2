<?php

use App\Models\CatalogItem;
use App\Models\EbaySweepMiss;
use App\Models\GradingCompany;

beforeEach(function () {
    GradingCompany::firstOrCreate(['slug' => 'psa'], ['name' => 'PSA']);
});

/** A Lorcana-style miss whose "#NNN" number the old resolver couldn't read. */
function lorcanaMiss(array $overrides = []): EbaySweepMiss
{
    return EbaySweepMiss::create(array_merge([
        'search_label' => 'lorcana-psa10',
        'source_listing_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        'title' => 'Disney Lorcana EN 1 #165 Worktogether PSA 10 Gem Mint',
        'price' => 5000,
        'sold_at' => now()->subDay(),
        'reason' => 'no_number',
        'parsed_number' => null,
        'best_catalog_item_id' => null,
        'best_score' => 0,
    ], $overrides));
}

test('resweep ingests a now-matching miss and clears it', function () {
    $card = CatalogItem::factory()->create([
        'name' => 'Worktogether',
        'number' => '165',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
    $miss = lorcanaMiss();

    $this->artisan('valuation:resweep-misses', ['--label' => 'lorcana-psa10'])
        ->assertSuccessful();

    expect(EbaySweepMiss::find($miss->id))->toBeNull()
        ->and($card->saleObservations()->count())->toBe(1)
        ->and($card->saleObservations()->first()->grade_label)->toBe('PSA 10');
});

test('a dry run writes nothing', function () {
    CatalogItem::factory()->create([
        'name' => 'Worktogether',
        'number' => '165',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
    $miss = lorcanaMiss();

    $this->artisan('valuation:resweep-misses', ['--dry-run' => true])
        ->assertSuccessful();

    expect(EbaySweepMiss::find($miss->id))->not->toBeNull();
});

test('a miss with no resolvable number is left unchanged', function () {
    $miss = lorcanaMiss([
        'title' => 'Disney Lorcana Azurite Sea Enchanted EN 6 PSA 10',
    ]);

    $this->artisan('valuation:resweep-misses')->assertSuccessful();

    $fresh = EbaySweepMiss::find($miss->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->reason)->toBe('no_number');
});
