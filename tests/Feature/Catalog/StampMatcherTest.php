<?php

use App\Models\CatalogItem;
use App\Support\Catalog\StampMatcher;

beforeEach(fn () => $this->m = new StampMatcher);

test('a base (unstamped) card rejects any stamped listing', function () {
    expect($this->m->matches(null, 'ho-oh 010/086 gamestop stamped promo'))->toBeFalse()
        ->and($this->m->matches(null, 'ho-oh 010/086 eb games promo'))->toBeFalse()
        ->and($this->m->matches(null, 'ho-oh 010/086 stamped'))->toBeFalse()
        // A plain listing is fine for the base card.
        ->and($this->m->matches(null, 'ho-oh 010/086 holo'))->toBeTrue();
});

test('a stamped card takes its own stamp and never another', function () {
    // GameStop card: takes GameStop, rejects EB Games and the plain base sale.
    expect($this->m->matches('gamestop', 'ho-oh 010/086 gamestop stamped promo'))->toBeTrue()
        ->and($this->m->matches('gamestop', 'ho-oh 010/086 eb games promo'))->toBeFalse()
        ->and($this->m->matches('gamestop', 'ho-oh 010/086 holo'))->toBeFalse();

    // EB Games card is the mirror image.
    expect($this->m->matches('eb_games', 'ho-oh 010/086 eb games stamped'))->toBeTrue()
        ->and($this->m->matches('eb_games', 'ho-oh 010/086 gamestop promo'))->toBeFalse();
});

test('a custom (typed) stamp matches by its own words', function () {
    expect($this->m->matches('costco', 'ho-oh 010/086 costco exclusive stamped'))->toBeTrue()
        // A GameStop listing must not fall into a Costco card.
        ->and($this->m->matches('costco', 'ho-oh 010/086 gamestop stamped'))->toBeFalse();
});

test('itemStamp reads the facet, or falls back to a stamp baked into the name', function () {
    $facet = CatalogItem::factory()->create(['name' => 'Ho-Oh', 'attributes' => ['language' => 'en', 'stamp' => 'gamestop']]);
    $named = CatalogItem::factory()->create(['name' => 'Ho-Oh [Gamestop]', 'attributes' => ['language' => 'en']]);
    $base = CatalogItem::factory()->create(['name' => 'Ho-Oh', 'attributes' => ['language' => 'en']]);

    expect($this->m->itemStamp($facet))->toBe('gamestop')
        ->and($this->m->itemStamp($named))->toBe('gamestop')
        ->and($this->m->itemStamp($base))->toBeNull();
});

test('canonical + label normalise and humanise', function () {
    expect($this->m->canonical('EB Games'))->toBe('eb_games')
        ->and($this->m->canonical('  GameStop '))->toBe('gamestop')
        ->and($this->m->label('eb_games'))->toBe('EB Games')
        ->and($this->m->label('costco'))->toBe('Costco')
        ->and($this->m->searchTerm(null))->toBeNull()
        ->and($this->m->searchTerm('gamestop'))->toBe('GameStop');
});
