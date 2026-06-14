<?php

use App\Actions\Reconcile\ReconcileSet;
use App\Models\CatalogItem;
use App\Models\PricechartingProduct;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Reconcile\ReconcileChange;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->for($this->vertical)->create(['slug' => 'pokemon']);
    $this->set = Set::factory()->for($this->line)->create(['slug' => 'base', 'name' => 'Base', 'language' => 'en']);

    // We hold the Unlimited holo Charizard (edition unset).
    $this->charizard = CatalogItem::factory()->for($this->vertical)->for($this->line)->for($this->set)
        ->create(['name' => 'Charizard', 'number' => '4', 'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo']]);
});

function pcProduct(array $attrs): PricechartingProduct
{
    return PricechartingProduct::create(array_merge([
        'pc_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        'console_name' => 'Pokemon Base Set',
        'language' => 'en',
        'set_id' => test()->set->id,
        'card_name' => 'Charizard',
        'number' => '4',
        'is_sealed' => false,
        'price_psa10' => 3010000,
    ], $attrs));
}

test('it assigns Unlimited to an edition-less base card, adds the missing editions, and flags errors/sealed', function () {
    pcProduct(['product_name' => 'Charizard #4', 'edition' => null]);                       // Unlimited
    pcProduct(['product_name' => 'Charizard [1st Edition] #4', 'edition' => 'first_edition']);
    pcProduct(['product_name' => 'Charizard [Shadowless] #4', 'edition' => 'shadowless']);
    pcProduct(['product_name' => 'Charizard [Black Dot Error] #4', 'finish' => 'black_dot_error']);
    pcProduct(['product_name' => 'Blastoise #2', 'card_name' => 'Blastoise', 'number' => '2']); // we lack #2
    pcProduct(['product_name' => 'Booster Box', 'card_name' => 'Booster Box', 'number' => null, 'is_sealed' => true]);

    $changes = collect((new ReconcileSet)($this->set));
    $byAction = $changes->keyBy('action');

    // Unlimited → label the existing Charizard.
    $fix = $changes->firstWhere('action', ReconcileChange::FIX_LABEL);
    expect($fix)->not->toBeNull()
        ->and($fix->catalogItemId)->toBe($this->charizard->id)
        ->and($fix->diff)->toBe(['edition' => [null, 'unlimited']])
        ->and($fix->confidence)->toBe('high');

    // 1st Edition + Shadowless → add printings, holo-ness inherited from the base.
    $printings = $changes->where('action', ReconcileChange::ADD_PRINTING);
    expect($printings)->toHaveCount(2);
    $firstEd = $printings->firstWhere('attributes.edition', 'first_edition');
    expect($firstEd->attributes)->toMatchArray(['variant' => 'holo', 'edition' => 'first_edition'])
        ->and($firstEd->baseItemId)->toBe($this->charizard->id)
        ->and($firstEd->confidence)->toBe('high');

    // Error printing → finish, Unlimited default edition.
    $error = $changes->firstWhere('action', ReconcileChange::ADD_ERROR_VARIANT);
    expect($error->attributes)->toMatchArray(['variant' => 'holo', 'edition' => 'unlimited', 'finish' => 'black_dot_error']);

    // Missing card + sealed → low-confidence review.
    expect($byAction[ReconcileChange::ADD_CARD]->confidence)->toBe('low');
    expect($byAction[ReconcileChange::ADD_SEALED]->confidence)->toBe('low');
});

test('an already-correct printing only proposes a link, then nothing once linked', function () {
    // Modern-style set: no editions anywhere.
    $set = Set::factory()->for($this->line)->create(['slug' => 'surging-sparks', 'name' => 'Surging Sparks', 'language' => 'en']);
    $pika = CatalogItem::factory()->for($this->vertical)->for($this->line)->for($set)
        ->create(['name' => 'Pikachu', 'number' => '58', 'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'holo']]);

    PricechartingProduct::create([
        'pc_id' => '555', 'console_name' => 'Pokemon Surging Sparks', 'language' => 'en', 'set_id' => $set->id,
        'card_name' => 'Pikachu', 'number' => '58', 'is_sealed' => false, 'product_name' => 'Pikachu #58',
    ]);

    $first = collect((new ReconcileSet)($set));
    expect($first->firstWhere('action', ReconcileChange::LINK)->catalogItemId)->toBe($pika->id);

    // Once linked, re-running proposes nothing.
    $pika->forceFill(['external_ids' => ['pricecharting_id' => '555']])->save();
    expect((new ReconcileSet)($set))->toBe([]);
});
