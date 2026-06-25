<?php

use App\Models\CatalogItem;
use App\Models\EbaySweepMiss;
use App\Models\EbaySweepOverride;
use App\Models\GradingCompany;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.anthropic.key' => 'test-key']);
    GradingCompany::firstOrCreate(['slug' => 'psa'], ['name' => 'PSA']);

    $this->card = CatalogItem::factory()->create([
        'name' => 'Charizard ex', 'number' => '223/197',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);
});

/** Fake the batched extractor response for a single card at index 0. */
function fakeExtract(array $card): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'tool_use',
                'name' => 'record_cards',
                'input' => ['cards' => [['index' => 0] + $card]],
            ]],
        ], 200),
    ]);
}

function chmiss(array $overrides = []): EbaySweepMiss
{
    return EbaySweepMiss::create(array_merge([
        'search_label' => 'pokemon-psa10',
        'source_listing_id' => '12345',
        'title' => 'Charizard ex 223/197 PSA 10 Obsidian Flames',
        'price' => 50000,
        'reason' => 'unmatched',
    ], $overrides));
}

test('a confident AI match auto-applies the sale and clears the miss', function () {
    $miss = chmiss();
    fakeExtract([
        'name' => 'Charizard ex', 'number' => '223/197', 'set_name' => 'Obsidian Flames',
        'language' => 'en', 'is_graded' => true, 'grading_company' => 'psa', 'grade' => 10, 'confidence' => 0.95,
    ]);

    $this->artisan('valuation:ai-match-misses', ['--limit' => 10])->assertSuccessful();

    expect(EbaySweepMiss::find($miss->id))->toBeNull()
        ->and($this->card->saleObservations()->where('source_listing_id', '12345')->first()?->grade_label)->toBe('PSA 10')
        ->and(EbaySweepOverride::where('source_listing_id', '12345')->where('action', EbaySweepOverride::REASSIGN)->exists())->toBeTrue();
});

test('a low-confidence AI match is recorded as a best-guess, not applied', function () {
    $miss = chmiss();
    fakeExtract([
        'name' => 'Charizard ex', 'number' => '223/197', 'set_name' => 'Obsidian Flames',
        'language' => 'en', 'is_graded' => true, 'grading_company' => 'psa', 'grade' => 10, 'confidence' => 0.35,
    ]);

    $this->artisan('valuation:ai-match-misses', ['--limit' => 10])->assertSuccessful();

    $fresh = EbaySweepMiss::find($miss->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->best_catalog_item_id)->toBe($this->card->id)
        ->and($this->card->saleObservations()->count())->toBe(0);
});

test('suggest-only never applies even when confident', function () {
    $miss = chmiss();
    fakeExtract([
        'name' => 'Charizard ex', 'number' => '223/197', 'language' => 'en',
        'is_graded' => true, 'grading_company' => 'psa', 'grade' => 10, 'confidence' => 0.95,
    ]);

    $this->artisan('valuation:ai-match-misses', ['--limit' => 10, '--suggest-only' => true])->assertSuccessful();

    expect(EbaySweepMiss::find($miss->id))->not->toBeNull()
        ->and($this->card->saleObservations()->count())->toBe(0);
});

test('an admin-rejected listing is skipped', function () {
    $miss = chmiss();
    EbaySweepOverride::create(['source_listing_id' => '12345', 'action' => EbaySweepOverride::REJECT]);
    fakeExtract(['name' => 'Charizard ex', 'number' => '223/197', 'is_graded' => false, 'confidence' => 0.95]);

    $this->artisan('valuation:ai-match-misses', ['--limit' => 10])->assertSuccessful();

    expect(EbaySweepMiss::find($miss->id))->not->toBeNull()
        ->and($this->card->saleObservations()->count())->toBe(0);
});
