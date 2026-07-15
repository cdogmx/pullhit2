<?php

use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Scanning\CandidateMatcher;
use App\Support\Scanning\IdentifiedCard;

function card(array $attrs = []): CatalogItem
{
    return CatalogItem::factory()->create(array_merge([
        'name' => 'Pikachu ex',
        'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'SIR', 'variant' => 'holofoil'],
    ], $attrs));
}

test('it resolves a card by number + language + name', function () {
    $en = card();

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Pikachu ex', number: '276/217', setName: null, language: 'en', confidence: 0.9,
    ));

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['item']->id)->toBe($en->id)
        ->and($matches[0]['score'])->toBeGreaterThan(0.5)
        ->and($matches[0]['reasons'])->toContain('number');
});

test('language is a hard filter', function () {
    card(['attributes' => ['language' => 'en', 'rarity' => 'SIR', 'variant' => 'holofoil']]);
    $ja = card(['attributes' => ['language' => 'ja', 'rarity' => 'SIR', 'variant' => 'holofoil']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Pikachu ex', number: '276/217', setName: null, language: 'ja', confidence: 0.9,
    ));

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['item']->id)->toBe($ja->id);
});

test('a card not in the catalog yields no candidates', function () {
    card();

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Snorlax', number: '199/165', setName: null, language: 'en', confidence: 0.9,
    ));

    expect($matches)->toBe([]);
});

test('the detected edition ranks the matching printing first', function () {
    $unlimited = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo', 'edition' => 'unlimited']]);
    $firstEd = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo', 'edition' => 'first_edition']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Charizard', number: '4', setName: null, language: 'en', confidence: 0.9, edition: 'first_edition',
    ));

    expect($matches[0]['item']->id)->toBe($firstEd->id)
        ->and($matches[0]['reasons'])->toContain('edition')
        ->and(collect($matches)->firstWhere('item.id', $unlimited->id)['score'])
        ->toBeLessThan($matches[0]['score']);
});

test('an exact number match is not crowded out by a flood of shared-name cards', function () {
    // A broad token like "VMAX" matches hundreds of (more popular) cards. The
    // real card must still be scored — regression for an unordered fetch window
    // that dropped the exact match, leaving only a weak base-name match on top.
    CatalogItem::factory()->count(105)->create([
        'name' => 'Decoy VMAX',
        'number' => '999',
        'popularity' => 1000,
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    $exact = CatalogItem::factory()->create([
        'name' => 'Gengar VMAX',
        'number' => '271',
        'popularity' => 0,
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Gengar VMAX', number: '271/264', setName: 'Fusion Strike',
        language: 'en', confidence: 0.97, variant: 'holo',
    ));

    expect($matches[0]['item']->id)->toBe($exact->id)
        ->and($matches[0]['reasons'])->toContain('number');
});

test('a zero-padded collector number matches the catalog plain number', function () {
    $nymble = CatalogItem::factory()->create([
        'name' => 'Nymble', 'number' => '96',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare'],
    ]);
    // A different printing with the everyday number, to ensure number wins.
    CatalogItem::factory()->create([
        'name' => 'Nymble', 'number' => '15',
        'attributes' => ['language' => 'en', 'rarity' => 'Common'],
    ]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Nymble', number: '096/094', setName: null, language: 'en', confidence: 0.9,
    ));

    expect($matches[0]['item']->id)->toBe($nymble->id)
        ->and($matches[0]['reasons'])->toContain('number');
});

test('a number-only match with a mismatched name is dropped', function () {
    // A Trainer that happens to share the number but nothing else — the real
    // card (Meowth #105) isn't in the catalog.
    CatalogItem::factory()->create([
        'name' => 'N', 'number' => '105',
        'attributes' => ['language' => 'en', 'rarity' => 'Uncommon'],
    ]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Meowth', number: '105/091', setName: null, language: 'en', confidence: 0.9,
    ));

    expect($matches)->toBe([]);
});

test('a single shared set-name word is not a set match', function () {
    // "Hidden Fates" vs "Paldean Fates" share only "Fates" — a wrong-name,
    // number-only coincidence must still be dropped, not propped up by it.
    CatalogItem::factory()->create([
        'name' => 'Varoom', 'number' => '64',
        'set_id' => Set::factory()->create(['name' => 'Paldean Fates']),
        'attributes' => ['language' => 'en', 'rarity' => 'Common'],
    ]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Ho-Oh & Reshiram-GX', number: '64/68', setName: 'Hidden Fates',
        language: 'en', confidence: 0.7,
    ));

    expect($matches)->toBe([]);
});

test('the set code disambiguates same-name cards across sets', function () {
    $mew = Set::factory()->create(['name' => '151', 'code' => 'MEW']);
    $obf = Set::factory()->create(['name' => 'Obsidian Flames', 'code' => 'OBF']);
    $inMew = CatalogItem::factory()->create(['name' => 'Charizard ex', 'number' => '6',
        'set_id' => $mew->id, 'attributes' => ['language' => 'en']]);
    CatalogItem::factory()->create(['name' => 'Charizard ex', 'number' => '125',
        'set_id' => $obf->id, 'attributes' => ['language' => 'en']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Charizard ex', number: null, setName: null, language: 'en', setCode: 'MEW',
    ));

    expect($matches[0]['item']->id)->toBe($inMew->id)
        ->and($matches[0]['reasons'])->toContain('set_code');
});

test('a shared mechanic word (Mega/EX) alone is not a name match', function () {
    // A scan of "Mega Greninja ex" must NOT collapse onto "Mega Charizard ex" —
    // they share only the mechanic tokens "mega"/"ex", not the core name.
    CatalogItem::factory()->create(['name' => 'Mega Charizard ex', 'number' => '125',
        'attributes' => ['language' => 'en']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Mega Greninja ex', number: null, setName: null, language: 'en',
    ));

    expect($matches)->toBe([]);
});

test('an exact name outranks one carrying an extra identity word', function () {
    $plain = CatalogItem::factory()->create(['name' => 'Greninja ex', 'number' => '106',
        'attributes' => ['language' => 'en']]);
    $mega = CatalogItem::factory()->create(['name' => 'Mega Greninja ex', 'number' => '122',
        'attributes' => ['language' => 'en']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Greninja ex', number: null, setName: null, language: 'en',
    ));

    expect($matches[0]['item']->id)->toBe($plain->id)
        ->and(collect($matches)->firstWhere('item.id', $mega->id)['score'])
        ->toBeLessThan($matches[0]['score']);
});

test('a number + set-code coincidence never beats the read name', function () {
    // The AI misreads the number/code; a wrong-name card that happens to share
    // them ("Drowzee #16 SFA") must NOT win over the correctly-named card.
    $sfa = Set::factory()->create(['name' => 'Shrouded Fable', 'code' => 'SFA']);
    CatalogItem::factory()->create(['name' => 'Drowzee', 'number' => '16',
        'set_id' => $sfa->id, 'attributes' => ['language' => 'en']]);
    $right = CatalogItem::factory()->create(['name' => 'Cinccino ex', 'number' => '105',
        'attributes' => ['language' => 'en']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Cinccino ex', number: '016/064', setName: null, language: 'en', setCode: 'SFA',
    ));

    expect($matches[0]['item']->id)->toBe($right->id)
        ->and(collect($matches)->pluck('item.name'))->not->toContain('Drowzee');
});

test('an exact name beats a partial-name card riding a number coincidence', function () {
    $exact = CatalogItem::factory()->create(['name' => 'Energy Recycler', 'number' => '108',
        'attributes' => ['language' => 'en']]);
    // Shares only the common word "Energy" and matches the (misread) number.
    CatalogItem::factory()->create(['name' => 'V Guard Energy', 'number' => '169',
        'attributes' => ['language' => 'en']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Energy Recycler', number: '169/089', setName: null, language: 'en',
    ));

    expect($matches[0]['item']->id)->toBe($exact->id);
});

test('a set-coded number is fetched verbatim and survives a name misread', function () {
    // One Piece numbers ("OP13-098") carry a set prefix the numerator strips, so
    // the card must be fetched by its raw number. It should win on the exact
    // number even when the AI grabbed the wrong text as the name.
    $op = Set::factory()->create(['name' => 'Carrying on His Will', 'code' => 'OP13']);
    $right = CatalogItem::factory()->create(['name' => 'Never Existed In The First Place',
        'number' => 'OP13-098', 'set_id' => $op->id, 'attributes' => ['language' => 'en']]);
    // A same-word Lorcana coincidence that must not win.
    CatalogItem::factory()->create(['name' => 'The Cold Never Bothered Me', 'number' => '130',
        'attributes' => ['language' => 'en']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Never Existed in the First Place', number: 'OP13-098', setName: null, language: 'en',
    ));

    expect($matches[0]['item']->id)->toBe($right->id)
        ->and($matches[0]['reasons'])->toContain('number');
});

test('a correctly-named card is not crowded out by a common collector number', function () {
    // 120 popular cards share the number "40"; the unpopular, exactly-named card
    // must still make the fetch window (ordering leads with the name).
    CatalogItem::factory()->count(120)->create([
        'name' => 'Filler Card', 'number' => '40', 'popularity' => 500,
        'attributes' => ['language' => 'en'],
    ]);
    $right = CatalogItem::factory()->create([
        'name' => 'Sneezy - Startlingly Loud', 'number' => '42', 'popularity' => 1,
        'attributes' => ['language' => 'en'],
    ]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Sneezy - Startlingly Loud', number: '40/204', setName: null, language: 'en',
    ));

    expect($matches[0]['item']->id)->toBe($right->id);
});

test('a detected reverse holo demotes the non-reverse printing', function () {
    $holo = CatalogItem::factory()->create(['name' => 'Pikachu', 'number' => '58',
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'holo']]);
    $reverse = CatalogItem::factory()->create(['name' => 'Pikachu', 'number' => '58',
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'reverse_holo']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Pikachu', number: '58', setName: null, language: 'en', confidence: 0.9, variant: 'reverse_holo',
    ));

    expect($matches[0]['item']->id)->toBe($reverse->id);
});
