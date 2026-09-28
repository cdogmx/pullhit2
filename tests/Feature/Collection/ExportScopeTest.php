<?php

use App\Models\CatalogItem;
use App\Models\User;

/**
 * Export follows the collection on screen.
 *
 * It used to filter on user_id alone, so somebody viewing "For sale" and
 * pressing Export got every card they own. On a large account that is thousands
 * of unrelated rows, and the mistake is quiet — the file looks right until you
 * count it.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'collector']);

    $this->main = $this->user->defaultCollection();
    $this->forSale = $this->user->collections()->create([
        'name' => 'For sale', 'slug' => 'for-sale',
    ]);

    $this->inMain = CatalogItem::factory()->create(['name' => 'Kept Card']);
    $this->inForSale = CatalogItem::factory()->create(['name' => 'Listed Card']);

    foreach ([[$this->main, $this->inMain], [$this->forSale, $this->inForSale]] as [$collection, $card]) {
        $collection->items()->create([
            'user_id' => $this->user->id,
            'catalog_item_id' => $card->id,
            'quantity' => 1,
            'condition' => 'NM',
        ]);
    }
});

function csv(\Illuminate\Testing\TestResponse $response): string
{
    return $response->streamedContent();
}

test('it exports only the collection asked for', function () {
    $body = csv($this->actingAs($this->user)->get('/collection/export?collection=for-sale'));

    expect($body)->toContain('Listed Card')
        ->not->toContain('Kept Card');
});

test('the filename names the collection', function () {
    // So two exports taken the same day do not overwrite each other in the
    // downloads folder.
    $this->actingAs($this->user)
        ->get('/collection/export?collection=for-sale')
        ->assertDownload('cardfoo-for-sale-'.now()->format('Y-m-d').'.csv');
});

test('no collection named still exports everything', function () {
    // The old behaviour, kept: a bare /collection/export is a whole-account
    // backup, and someone may have bookmarked it.
    $body = csv($this->actingAs($this->user)->get('/collection/export'));

    expect($body)->toContain('Listed Card')
        ->toContain('Kept Card');
});

test('an unknown collection falls back to the default, not to everything', function () {
    // A stale or mistyped slug must not quietly widen the export — that is the
    // bug being fixed, arriving by another route.
    $body = csv($this->actingAs($this->user)->get('/collection/export?collection=does-not-exist'));

    expect($body)->toContain('Kept Card')
        ->not->toContain('Listed Card');
});

test('one person cannot export another person\'s collection', function () {
    $stranger = User::factory()->create();

    $body = csv($this->actingAs($stranger)->get('/collection/export?collection=for-sale'));

    // The slug is looked up within the caller's own collections, so a stranger
    // asking for it gets their own default rather than somebody else's cards.
    expect($body)->not->toContain('Listed Card')
        ->not->toContain('Kept Card');
});

test('the export names the rarity and the printing', function () {
    // A foil and its normal sibling share a name and a number, so without the
    // printing column two rows in the file are indistinguishable — and on
    // Lorcana they are not worth the same.
    $foil = CatalogItem::factory()->create([
        'name' => 'Elsa - Spirit of Winter',
        'number' => '42',
        'attributes' => ['language' => 'en', 'variant' => 'foil', 'rarity' => 'Legendary'],
    ]);
    $this->forSale->items()->create([
        'user_id' => $this->user->id, 'catalog_item_id' => $foil->id,
        'quantity' => 1, 'condition' => 'NM',
    ]);

    $body = csv($this->actingAs($this->user)->get('/collection/export?collection=for-sale'));

    expect($body)->toContain('Rarity')
        ->toContain('Variant')
        ->toContain('Legendary')
        // Written the way a person reads it, not "foil".
        ->toContain('Foil');
});

test('a plain normal printing leaves the variant column empty', function () {
    // On a card with one printing it would be noise in every row; the column is
    // there to separate the ones that have siblings.
    $body = csv($this->actingAs($this->user)->get('/collection/export?collection=for-sale'));

    expect($body)->not->toContain('Normal');
});
