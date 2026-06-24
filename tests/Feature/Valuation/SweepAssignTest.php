<?php

use App\Models\CatalogItem;
use App\Models\EbaySweepMiss;
use App\Models\EbaySweepOverride;
use App\Models\User;

test('an admin can assign an unmatched sweep listing to a card', function () {
    $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    $card = CatalogItem::factory()->create(['name' => 'Pikachu ex', 'number' => '238']);

    $miss = EbaySweepMiss::create([
        'search_label' => 'pokemon-psa10',
        'source_listing_id' => '998877',
        'title' => 'Pikachu ex 238/191 Surging Sparks PSA 10',
        'price' => 50000,
        'reason' => 'no_number',
    ]);

    $this->actingAs($admin)
        ->post("/admin/ebay-sweep/misses/{$miss->id}/assign", ['catalog_item_id' => $card->id])
        ->assertRedirect();

    // The miss is cleared, a real comp lands on the card, and the listing is
    // pinned to it for future sweeps.
    expect(EbaySweepMiss::find($miss->id))->toBeNull()
        ->and($card->saleObservations()->where('source_listing_id', '998877')->where('is_synthetic', false)->exists())->toBeTrue()
        ->and(EbaySweepOverride::where('source_listing_id', '998877')
            ->where('action', EbaySweepOverride::REASSIGN)
            ->where('catalog_item_id', $card->id)->exists())->toBeTrue();
});

test('a non-admin cannot assign a sweep listing', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $card = CatalogItem::factory()->create();
    $miss = EbaySweepMiss::create([
        'search_label' => 's', 'source_listing_id' => '1', 'title' => 't', 'price' => 100, 'reason' => 'unmatched',
    ]);

    $this->actingAs($user)
        ->post("/admin/ebay-sweep/misses/{$miss->id}/assign", ['catalog_item_id' => $card->id])
        ->assertForbidden();
});
