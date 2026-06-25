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

test('an admin can reject an unmatched listing, suppressing it', function () {
    $admin = User::factory()->create(['is_admin' => true, 'username' => 'adm', 'email_verified_at' => now()]);

    $miss = EbaySweepMiss::create([
        'search_label' => 'lorcana-psa10',
        'source_listing_id' => '551122',
        'title' => 'Random junk lot not a single card',
        'price' => 999,
        'reason' => 'unmatched',
    ]);

    $this->actingAs($admin)
        ->post("/admin/ebay-sweep/misses/{$miss->id}/reject")
        ->assertRedirect();

    // The miss is gone and the listing is pinned as rejected for future sweeps.
    expect(EbaySweepMiss::find($miss->id))->toBeNull()
        ->and(EbaySweepOverride::where('source_listing_id', '551122')
            ->where('action', EbaySweepOverride::REJECT)->exists())->toBeTrue();
});

test('a non-admin cannot reject a sweep listing', function () {
    $user = User::factory()->create(['username' => 'plain', 'email_verified_at' => now()]);
    $miss = EbaySweepMiss::create([
        'search_label' => 's', 'source_listing_id' => '2', 'title' => 't', 'price' => 100, 'reason' => 'unmatched',
    ]);

    $this->actingAs($user)
        ->post("/admin/ebay-sweep/misses/{$miss->id}/reject")
        ->assertForbidden();

    expect(EbaySweepMiss::find($miss->id))->not->toBeNull();
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
