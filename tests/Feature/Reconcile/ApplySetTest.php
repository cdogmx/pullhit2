<?php

use App\Actions\Reconcile\ApplySet;
use App\Models\CatalogItem;
use App\Models\PricechartingProduct;
use App\Models\ProductLine;
use App\Models\ReconciliationChange;
use App\Models\Set;
use App\Models\Vertical;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->for($this->vertical)->create(['slug' => 'pokemon']);
    $this->set = Set::factory()->for($this->line)->create(['slug' => 'base', 'name' => 'Base', 'language' => 'en']);

    $this->charizard = CatalogItem::factory()->for($this->vertical)->for($this->line)->for($this->set)->create([
        'name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo'],
        'primary_image_path' => 'https://img/charizard.png',
    ]);

    $make = fn (array $a) => PricechartingProduct::create(array_merge([
        'pc_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        'console_name' => 'Pokemon Base Set', 'language' => 'en', 'set_id' => $this->set->id,
        'card_name' => 'Charizard', 'number' => '4', 'is_sealed' => false, 'price_ungraded' => 724031,
    ], $a));

    $make(['product_name' => 'Charizard #4', 'edition' => null, 'price_ungraded' => 38056]);
    $make(['product_name' => 'Charizard [1st Edition] #4', 'edition' => 'first_edition']);
    $make(['product_name' => 'Charizard [Shadowless] #4', 'edition' => 'shadowless']);
    $make(['product_name' => 'Charizard [Black Dot Error] #4', 'finish' => 'black_dot_error']);
    $make(['product_name' => 'Booster Box', 'card_name' => 'Booster Box', 'number' => null, 'is_sealed' => true]);
});

test('apply assigns Unlimited, adds the editions with seeded values, and queues the rest', function () {
    $result = app(ApplySet::class)($this->set);

    // FIX_LABEL (assign unlimited) + 2 ADD_PRINTING (1st Ed, Shadowless) auto-applied.
    expect($result['applied'])->toBe(3)
        ->and($result['queued'])->toBe(2); // error + sealed

    // The existing Charizard is now labeled Unlimited.
    expect($this->charizard->fresh()->attributes['edition'])->toBe('unlimited');

    // The new printings exist, grouped under the same base card, linked + valued.
    $printings = CatalogItem::where('set_id', $this->set->id)->where('number', '4')->get();
    expect($printings)->toHaveCount(3); // unlimited + first_edition + shadowless

    $firstEd = $printings->first(fn ($i) => ($i->attributes['edition'] ?? null) === 'first_edition');
    expect($firstEd->attributes)->toMatchArray(['variant' => 'holo', 'edition' => 'first_edition'])
        ->and($firstEd->base_key)->toBe($this->charizard->fresh()->base_key)
        ->and($firstEd->external_ids['pricecharting_id'])->not->toBeNull()
        ->and($firstEd->primary_image_path)->toBe('https://img/charizard.png') // inherited
        ->and($firstEd->fresh()->loadMissing('defaultMarketValue')->defaultMarketValue)->not->toBeNull();

    // Audit: applied + pending recorded.
    expect(ReconciliationChange::where('status', 'applied')->count())->toBe(3)
        ->and(ReconciliationChange::where('status', 'pending')->count())->toBe(2);
});

test('re-applying is idempotent', function () {
    app(ApplySet::class)($this->set);
    $countAfterFirst = CatalogItem::count();

    $result = app(ApplySet::class)($this->set);

    expect($result['applied'])->toBe(0)
        ->and(CatalogItem::count())->toBe($countAfterFirst);
});
