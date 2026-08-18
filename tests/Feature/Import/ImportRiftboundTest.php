<?php

use App\Actions\Catalog\ImportRiftbound;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Support\Facades\Http;

function riftCard(string $id, string $publicCode, int $collectorNumber, string $name, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'collectorNumber' => $collectorNumber,
        'name' => $name,
        'publicCode' => $publicCode,
        'set' => ['value' => ['id' => 'UNL', 'label' => 'Unleashed']],
        'cardType' => ['type' => [['id' => 'spell', 'label' => 'Spell']]],
        'rarity' => ['value' => ['id' => 'common', 'label' => 'Common']],
        'domain' => ['values' => [['id' => 'chaos', 'label' => 'Chaos']]],
        'illustrator' => ['values' => [['id' => 'kudos', 'label' => 'Kudos Productions']]],
        'cardImage' => ['url' => 'https://cmsassets.example/card.png'],
        'energy' => ['value' => ['id' => 2, 'label' => '2']],
        'text' => 'Counter a spell.',
    ], $overrides);
}

function fakeRiftFeed(array $cards): void
{
    // The feed pages; a page beyond the data comes back empty, which is what
    // ends pagination (totalItems over-reports, so it cannot be trusted).
    Http::fake([
        '*riftbound_gallery_cards*from=0*' => Http::response(['data' => $cards, 'metadata' => ['totalItems' => 999]]),
        '*riftbound_gallery_cards*' => Http::response(['data' => [], 'metadata' => ['totalItems' => 999]]),
    ]);
}

test('it imports cards into a riftbound product line and its sets', function () {
    fakeRiftFeed([
        riftCard('unl-001-219', 'UNL-001/219', 1, 'Arena Kingpin'),
        riftCard('ven-150-166', 'VEN-150/166', 150, 'Acceleration Gate', [
            'set' => ['value' => ['id' => 'VEN', 'label' => 'Vendetta']],
            'rarity' => ['value' => ['id' => 'epic', 'label' => 'Epic']],
        ]),
    ]);

    $result = app(ImportRiftbound::class)(withImages: false);

    expect($result['cards'])->toBe(2);

    $line = ProductLine::where('slug', 'riftbound')->first();
    expect($line)->not->toBeNull()
        ->and(Set::where('product_line_id', $line->id)->pluck('name')->sort()->values()->all())
        ->toBe(['Unleashed', 'Vendetta']);

    $card = CatalogItem::where('name', 'Arena Kingpin')->first();
    expect($card->number)->toBe('001/219')
        ->and($card->getAttribute('attributes')['rarity'])->toBe('Common')
        ->and($card->getAttribute('attributes')['type'])->toBe('Spell')
        ->and($card->getAttribute('attributes')['faction'])->toBe('Chaos')
        ->and($card->getAttribute('attributes')['illustrator'])->toBe('Kudos Productions')
        ->and($card->external_ids['riftbound_public_code'])->toBe('UNL-001/219');
});

test('a token does not collide with the card sharing its collector number', function () {
    // The feed gives "UNL-T01" collectorNumber 1, the same as "UNL-001/219".
    // identity_hash is built from set + name + number, so numbering on
    // collectorNumber would fold two different cards into one row.
    fakeRiftFeed([
        riftCard('unl-001-219', 'UNL-001/219', 1, 'Arena Kingpin'),
        riftCard('unl-t01', 'UNL-T01', 1, 'Baron Pit'),
    ]);

    app(ImportRiftbound::class)(withImages: false);

    expect(CatalogItem::count())->toBe(2)
        ->and(CatalogItem::where('name', 'Arena Kingpin')->value('number'))->toBe('001/219')
        ->and(CatalogItem::where('name', 'Baron Pit')->value('number'))->toBe('T01');
});

test('re-running the import upserts rather than duplicating', function () {
    $cards = [
        riftCard('unl-001-219', 'UNL-001/219', 1, 'Arena Kingpin'),
        riftCard('unl-t01', 'UNL-T01', 1, 'Baron Pit'),
    ];

    fakeRiftFeed($cards);
    app(ImportRiftbound::class)(withImages: false);

    fakeRiftFeed($cards);
    app(ImportRiftbound::class)(withImages: false);

    expect(CatalogItem::count())->toBe(2)
        ->and(Set::whereHas('productLine', fn ($q) => $q->where('slug', 'riftbound'))->count())->toBe(1);
});

test('a dual-domain card records both domains', function () {
    fakeRiftFeed([
        riftCard('unl-002-219', 'UNL-002/219', 2, 'Twin Disciplines', [
            'domain' => ['values' => [['id' => 'chaos', 'label' => 'Chaos'], ['id' => 'fury', 'label' => 'Fury']]],
        ]),
    ]);

    app(ImportRiftbound::class)(withImages: false);

    expect(CatalogItem::first()->getAttribute('attributes')['faction'])->toBe('Chaos, Fury');
});

test('pagination stops on an empty page rather than trusting totalItems', function () {
    // totalItems claims 999; the feed really has one card. Trusting the count
    // would loop to the page cap on every import.
    fakeRiftFeed([riftCard('unl-001-219', 'UNL-001/219', 1, 'Arena Kingpin')]);

    expect(app(ImportRiftbound::class)(withImages: false)['cards'])->toBe(1);

    // from=0 plus the one empty page that ended it.
    Http::assertSentCount(2);
});

test('a card with no public code still imports on a unique number', function () {
    fakeRiftFeed([riftCard('unl-999-x', 'UNL-777/219', 777, 'Oddity', ['publicCode' => null])]);

    app(ImportRiftbound::class)(withImages: false);

    expect(CatalogItem::first()->number)->toBe('unl-999-x');
});
