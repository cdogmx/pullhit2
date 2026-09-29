<?php

use App\Models\CatalogItem;
use App\Models\CompAdjudication;
use App\Models\MarketValue;
use App\Models\PriceHealthSnapshot;
use App\Models\SaleObservation;
use App\Models\User;

/**
 * The price-health page, and the limits on what a finding may do.
 *
 * Applying one deletes a real sale on a model's say-so, so it is a person's
 * decision and it has to reprice the card immediately — leaving the old value
 * behind is exactly the mistake that left 1,066 cards holding prices built from
 * comps that no longer existed.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->card = CatalogItem::factory()->create(['name' => 'Umbreon', 'number' => '17']);
    $this->other = CatalogItem::factory()->create(['name' => 'Umbreon', 'number' => '17']);

    $this->comp = SaleObservation::factory()->for($this->card)->create([
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 12000, 'is_synthetic' => false,
        'raw' => ['title' => 'Umbreon Gold Star 17/17 Celebrations Classic Collection', 'source' => 'ebay'],
    ]);

    $this->finding = CompAdjudication::create([
        'catalog_item_id' => $this->card->id,
        'sale_observation_id' => $this->comp->id,
        'reads_as_catalog_item_id' => $this->other->id,
        'price' => 12000,
        'title' => 'Umbreon Gold Star 17/17 Celebrations Classic Collection',
        'ratio' => 30.5,
        'status' => CompAdjudication::OPEN,
    ]);
});

test('the page shows the latest reading and the open findings', function () {
    PriceHealthSnapshot::create([
        'compared' => 8516,
        'buckets' => ['0.5–2x' => 8181, 'over 10x' => 1],
        'over_2x' => 57,
        'under_half' => 278,
    ]);

    $this->actingAs($this->admin)->get('/admin/price-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/price-health')
            ->where('latest.compared', 8516)
            ->where('latest.over_2x', 57)
            ->where('open', 1)
            ->where('findings.data.0.ratio', 30.5));
});

test('applying removes the comp and reprices the card', function () {
    MarketValue::factory()->for($this->card)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 12000, 'is_estimated' => false,
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/price-health/{$this->finding->id}/apply")
        ->assertRedirect();

    expect(SaleObservation::find($this->comp->id))->toBeNull()
        ->and($this->finding->fresh()->status)->toBe(CompAdjudication::APPLIED)
        ->and($this->finding->fresh()->reviewed_by)->toBe($this->admin->id)
        // The comp was the only one behind that value, so the value must go too.
        ->and(MarketValue::where('catalog_item_id', $this->card->id)->where('state_key', 'NM')->exists())
        ->toBeFalse();
});

test('dismissing keeps the comp and records that the model was wrong', function () {
    // Recorded rather than just closed: a run of dismissals on one shape is
    // evidence the thresholds are too loose, which nobody notices without a count.
    $this->actingAs($this->admin)
        ->post("/admin/price-health/{$this->finding->id}/dismiss")
        ->assertRedirect();

    expect(SaleObservation::find($this->comp->id))->not->toBeNull()
        ->and($this->finding->fresh()->status)->toBe(CompAdjudication::DISMISSED);
});

test('a finding cannot be decided twice', function () {
    $this->finding->forceFill(['status' => CompAdjudication::DISMISSED])->save();

    $this->actingAs($this->admin)
        ->post("/admin/price-health/{$this->finding->id}/apply")
        ->assertSessionHasErrors('finding');

    expect(SaleObservation::find($this->comp->id))->not->toBeNull();
});

test('a finding whose comp another pass already removed reads as stale', function () {
    // The prune and the sweep run on their own schedules, so a finding can point
    // at nothing by the time anyone opens the page.
    $this->comp->delete();

    $this->actingAs($this->admin)->get('/admin/price-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('findings.data.0.stale', true));
});

test('only admins can see it', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/price-health')
        ->assertForbidden();
});

test('moving reassigns the comp and reprices both cards', function () {
    // The better answer than deleting: the sale happened. On the Aquapolis Lugia
    // it is the difference between losing 25 real sales and handing them to the
    // 30th Celebration reprint that actually made them.
    MarketValue::factory()->for($this->card)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 12000, 'is_estimated' => false,
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/price-health/{$this->finding->id}/move")
        ->assertRedirect();

    expect($this->comp->fresh()->catalog_item_id)->toBe($this->other->id)
        ->and($this->finding->fresh()->status)->toBe(CompAdjudication::MOVED)
        // The card it left had only this comp, so its value goes with it.
        ->and(MarketValue::where('catalog_item_id', $this->card->id)->where('state_key', 'NM')->exists())
        ->toBeFalse()
        // And the card it joined now has one.
        ->and(MarketValue::where('catalog_item_id', $this->other->id)->where('state_key', 'NM')->exists())
        ->toBeTrue();
});

test('moving onto a card that already holds the listing drops the duplicate', function () {
    // A sweep may have placed the same listing correctly already. Moving would
    // leave the one sale on the card twice, which is its own pricing bug.
    SaleObservation::factory()->for($this->other)->create([
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
        'price' => 12000, 'is_synthetic' => false,
        'venue' => $this->comp->venue,
        'source_listing_id' => 'dupe-1',
        'raw' => ['title' => 'same listing', 'source' => 'ebay'],
    ]);
    $this->comp->forceFill(['source_listing_id' => 'dupe-1'])->save();

    $this->actingAs($this->admin)
        ->post("/admin/price-health/{$this->finding->id}/move")
        ->assertRedirect();

    expect(SaleObservation::find($this->comp->id))->toBeNull()
        ->and(SaleObservation::where('catalog_item_id', $this->other->id)->count())->toBe(1);
});

test('a finding cannot be moved once decided', function () {
    $this->finding->forceFill(['status' => CompAdjudication::DISMISSED])->save();

    $this->actingAs($this->admin)
        ->post("/admin/price-health/{$this->finding->id}/move")
        ->assertSessionHasErrors('finding');

    expect($this->comp->fresh()->catalog_item_id)->toBe($this->card->id);
});

test('a finding with no stored url still links, via its comp', function () {
    // Every finding recorded before the url column existed had none — 74 of
    // them — and rendered as plain text on the one page whose job is to let
    // somebody check the listing.
    $this->finding->forceFill(['url' => null])->save();
    $this->comp->forceFill([
        'raw' => ['title' => 'x', 'url' => 'https://www.ebay.com/itm/123'],
    ])->save();

    $this->actingAs($this->admin)->get('/admin/price-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('findings.data.0.url', 'https://www.ebay.com/itm/123'));
});

test('a stored url is preferred, so a decided finding keeps its link', function () {
    // Applying a finding deletes the comp, so the fallback disappears with it.
    $this->finding->forceFill(['url' => 'https://www.ebay.com/itm/stored'])->save();

    $this->actingAs($this->admin)->get('/admin/price-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('findings.data.0.url', 'https://www.ebay.com/itm/stored'));
});
