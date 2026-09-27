<?php

use App\Actions\Catalog\CreateProvisionalCard;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use App\Support\Scanning\IdentifiedCard;

/**
 * A scanned card nobody could match, made usable without being made canonical.
 *
 * The quarantine is the point. identity_hash is a function of the NAME, so a row
 * built from a read of "Charizard EX" never matches the official import's
 * "Charizard ex" and becomes a permanent duplicate. A misread number is worse:
 * comps match on set and number, so the row collects sales for a different card.
 * Both mistakes stay local to the scan until somebody confirms the row.
 */
beforeEach(function () {
    $this->vertical = Vertical::firstOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
    $this->action = app(CreateProvisionalCard::class);
});

function read(array $overrides = []): IdentifiedCard
{
    return IdentifiedCard::fromVision(array_merge([
        'name' => 'Ahsoka Tano',
        'number' => '042',
        'set_name' => 'Shadows of the Galaxy',
        'game' => 'Star Wars Unlimited',
        'language' => 'en',
        'is_graded' => false,
        'confidence' => 0.9,
    ], $overrides));
}

test('it creates the card, the set and the brand we do not hold yet', function () {
    $card = $this->action->__invoke(read(), User::factory()->create());

    expect($card)->not->toBeNull()
        ->and($card->is_provisional)->toBeTrue()
        ->and($card->name)->toBe('Ahsoka Tano')
        ->and($card->number)->toBe('042');

    $line = ProductLine::where('slug', 'star-wars-unlimited')->first();
    expect($line)->not->toBeNull()
        // The brand is quarantined too, or an unreviewed read shows up in
        // navigation as a game we support.
        ->and($line->is_provisional)->toBeTrue()
        ->and($card->set->is_provisional)->toBeTrue()
        ->and($card->set->name)->toBe('Shadows of the Galaxy');
});

test('it records the read and who scanned it', function () {
    $user = User::factory()->create();
    $card = $this->action->__invoke(read(), $user);

    expect($card->provisional_by)->toBe($user->id)
        ->and($card->provisional_read['product_line'])->toBe('Star Wars Unlimited')
        ->and($card->provisional_read['confidence'])->toBe(0.9)
        ->and($card->provisional_at)->not->toBeNull();
});

test('a second scan of the same card counts, rather than making another row', function () {
    // The duplicate trap. Two people scanning one card must reach one row.
    $first = $this->action->__invoke(read(), User::factory()->create());
    $second = $this->action->__invoke(read(), User::factory()->create());

    expect($second->id)->toBe($first->id)
        ->and($second->provisional_scans)->toBe(2)
        // Credit stays with whoever found it first.
        ->and($second->provisional_by)->toBe($first->provisional_by)
        ->and(CatalogItem::where('name', 'Ahsoka Tano')->count())->toBe(1);
});

test('it reuses a brand and set we already hold', function () {
    $line = ProductLine::factory()->create(['vertical_id' => $this->vertical->id, 'slug' => 'pokemon', 'name' => 'Pokémon']);
    $set = Set::factory()->create(['product_line_id' => $line->id, 'name' => 'Celebrations']);

    $card = $this->action->__invoke(read(['game' => 'Pokemon', 'set_name' => 'Celebrations']));

    // Matched on slug, so "Pokemon" from a read finds the "pokemon" line.
    expect($card->product_line_id)->toBe($line->id)
        ->and($card->set_id)->toBe($set->id)
        // The card is provisional; the set and brand it joined are not touched.
        ->and($card->is_provisional)->toBeTrue()
        ->and($set->fresh()->is_provisional)->toBeFalse()
        ->and($line->fresh()->is_provisional)->toBeFalse();
});

test('a confirmed row is not re-flagged by a later scan', function () {
    $card = $this->action->__invoke(read());
    $card->forceFill(['is_provisional' => false])->save();

    $again = $this->action->__invoke(read());

    // Confirming is a human decision; another scan must not undo it.
    expect($again->id)->toBe($card->id)
        ->and($again->is_provisional)->toBeFalse();
});

test('a read with no name makes nothing', function () {
    // The name feeds the identity hash, the slug and the URL. Without it there
    // is no row to make, and the scan still returns what it read.
    expect($this->action->__invoke(read(['name' => null])))->toBeNull()
        ->and($this->action->__invoke(read(['name' => '   '])))->toBeNull();
});

test('a read that does not say which game goes to a holding pen', function () {
    // Better than filing it under whichever brand happens to be first.
    $card = $this->action->__invoke(read(['game' => null]));

    expect($card->productLine->slug)->toBe('unidentified')
        ->and($card->productLine->is_provisional)->toBeTrue();
});

test('a read with no set still gets one, because comps need it', function () {
    $card = $this->action->__invoke(read(['set_name' => null]));

    expect($card->set)->not->toBeNull()
        ->and($card->set->name)->toBe('Unsorted')
        ->and($card->set->is_provisional)->toBeTrue();
});
