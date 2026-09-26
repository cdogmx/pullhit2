<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\PricechartingProduct;
use App\Models\SaleObservation;
use App\Models\Set;
use Illuminate\Support\Facades\Http;

/**
 * The AI adjudicator, and the limits on what it is allowed to conclude.
 *
 * It exists because one class of error is genuinely beyond a regex: a reprint
 * that shares its name, number and artwork with the original and differs by a
 * factor of forty. Everything else the classifier already handles, which is why
 * this runs over a few dozen divergent cards rather than a million comps.
 *
 * The model only READS a title into fields. The match is deterministic, so a
 * verdict can be replayed and explained — it is never asked whether to delete.
 */
beforeEach(function () {
    config(['services.anthropic.key' => 'test-key']);

    $this->set = Set::factory()->create(['name' => 'POP Series 5', 'slug' => 'pop-series-5']);

    // A card we price at $47 against a $4.70 reference: divergent, so judged.
    $this->card = CatalogItem::factory()->create([
        'set_id' => $this->set->id, 'name' => 'Umbreon', 'number' => '17',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);
    MarketValue::factory()->for($this->card)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 4700, 'is_estimated' => false,
    ]);
    PricechartingProduct::create([
        'pc_id' => 'pc-umbreon-17', 'console_name' => 'Pokemon POP Series 5',
        'product_name' => 'Umbreon #17', 'language' => 'en', 'set_id' => $this->set->id,
        'card_name' => 'Umbreon', 'number' => '17', 'is_sealed' => false, 'price_ungraded' => 470,
    ]);

    $this->comp = SaleObservation::factory()->for($this->card)->create([
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 4700, 'is_synthetic' => false,
        'raw' => ['title' => 'Umbreon Gold Star 17/17 Celebrations Classic Collection Holo', 'source' => 'ebay'],
    ]);
});

/** The extractor's batched tool-call shape, for one title at index 0. */
function fakeRead(array $card): void
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

test('it reports a comp that reads as a different card, and writes nothing', function () {
    $other = CatalogItem::factory()->create([
        'set_id' => Set::factory()->create(['name' => 'Celebrations: Classic Collection'])->id,
        'name' => 'Umbreon', 'number' => '17',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    fakeRead(['name' => 'Umbreon', 'number' => '17', 'set_name' => 'Celebrations: Classic Collection', 'language' => 'en', 'confidence' => 0.95]);

    $this->artisan('valuation:adjudicate-comps', ['--set' => $this->set->slug])
        ->assertSuccessful();

    // Suggest-only is the default: a model's reading is a lead, not a mandate.
    expect(SaleObservation::find($this->comp->id))->not->toBeNull();
});

test('--apply removes it and recomputes the card', function () {
    CatalogItem::factory()->create([
        'set_id' => Set::factory()->create(['name' => 'Celebrations: Classic Collection'])->id,
        'name' => 'Umbreon', 'number' => '17',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    fakeRead(['name' => 'Umbreon', 'number' => '17', 'set_name' => 'Celebrations: Classic Collection', 'language' => 'en', 'confidence' => 0.95]);

    $this->artisan('valuation:adjudicate-comps', ['--set' => $this->set->slug, '--apply' => true])
        ->assertSuccessful();

    expect(SaleObservation::find($this->comp->id))->toBeNull();
});

test('a low-confidence read never removes anything', function () {
    CatalogItem::factory()->create([
        'set_id' => Set::factory()->create(['name' => 'Celebrations: Classic Collection'])->id,
        'name' => 'Umbreon', 'number' => '17',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    // The model is unsure. Unsure is not evidence, and a comp is real money.
    fakeRead(['name' => 'Umbreon', 'number' => '17', 'set_name' => 'Celebrations: Classic Collection', 'language' => 'en', 'confidence' => 0.2]);

    $this->artisan('valuation:adjudicate-comps', ['--set' => $this->set->slug, '--apply' => true])
        ->assertSuccessful();

    expect(SaleObservation::find($this->comp->id))->not->toBeNull();
});

test('a comp that reads as this very card is left alone', function () {
    fakeRead(['name' => 'Umbreon', 'number' => '17', 'set_name' => 'POP Series 5', 'language' => 'en', 'confidence' => 0.95]);

    $this->artisan('valuation:adjudicate-comps', ['--set' => $this->set->slug, '--apply' => true])
        ->assertSuccessful();

    expect(SaleObservation::find($this->comp->id))->not->toBeNull();
});

test('cards that agree with the reference are never sent to the model', function () {
    // The whole cost argument. A card priced in line with PriceCharting is not
    // suspect, and paying to re-read its comps buys nothing.
    MarketValue::where('catalog_item_id', $this->card->id)->update(['median' => 500]);
    Http::fake(['api.anthropic.com/*' => Http::response([], 500)]); // would fail if called

    $this->artisan('valuation:adjudicate-comps', ['--set' => $this->set->slug, '--apply' => true])
        ->expectsOutputToContain('No divergent cards to judge')
        ->assertSuccessful();

    expect(SaleObservation::find($this->comp->id))->not->toBeNull();
});
