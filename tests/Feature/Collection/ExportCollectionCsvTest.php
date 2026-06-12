<?php

use App\Actions\Collection\AddToCollection;
use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->item = CatalogItem::factory()->create(['name' => 'Keldeo EX']);
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => Condition::NearMint, 'median' => 8459,
    ]);
    app(AddToCollection::class)($this->user, $this->item, [
        'condition' => 'NM', 'quantity' => 2, 'unit_cost' => 5000,
    ]);
});

test('export requires authentication', function () {
    $this->get('/collection/export')->assertRedirect('/login');
});

test('an authenticated user downloads their collection as csv', function () {
    $response = $this->actingAs($this->user)->get('/collection/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('Name,Set,Number') // header row
        ->and($csv)->toContain('Keldeo EX')    // the held card
        ->and($csv)->toContain('84.59')         // unit value in dollars
        ->and($csv)->toContain('169.18');       // market value = 2 × 84.59
});

test('the export only contains the requesting user\'s holdings', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $otherItem = CatalogItem::factory()->create(['name' => 'Secret Charizard']);
    app(AddToCollection::class)($other, $otherItem, [
        'condition' => 'NM', 'quantity' => 1, 'unit_cost' => 100,
    ]);

    $csv = $this->actingAs($this->user)->get('/collection/export')->streamedContent();

    expect($csv)->toContain('Keldeo EX')
        ->and($csv)->not->toContain('Secret Charizard');
});
