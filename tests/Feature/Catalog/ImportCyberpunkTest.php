<?php

use App\Actions\Catalog\ImportCyberpunk;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');

    Http::fake([
        // The client reads the set filter first to discover retail sets.
        'api.netdeck.gg/api/cards/cyberpunk/filters' => Http::response([
            'filters' => [
                ['key' => 'set', 'label' => 'Set', 'options' => [
                    ['code' => 'welcometonightcitybeta', 'name' => 'Welcome to Night City — Beta'],
                    ['code' => 'welcometonightcityretail', 'name' => 'Welcome to Night City — Retail'],
                ]],
            ],
        ], 200),
        'api.netdeck.gg/api/cards/cyberpunk*' => Http::response([
            'items' => [[
                'id' => '81a8dec7-9541-4020-93e1-7d798a57dcbc',
                'external_id' => 'cb-v-streetkid',
                'name' => 'V — StreetKid',
                'display_name' => 'V — StreetKid',
                'slug' => 'v-streetkid',
                'rules_text' => '{Call} Trash 3.',
                'printing_id' => '3fc63c58-5954-4744-a5af-047bfc5cb159',
                'set' => ['code' => 'welcometonightcityretail', 'name' => 'Welcome to Night City — Retail'],
                'rarity' => 'Rare',
                'image_url' => 'https://cdn.example.com/v.webp',
                'color' => 'Red',
                'card_type' => 'Legend',
                'classifications' => ['Merc'],
                'cost' => 5, 'power' => 6, 'ram' => 2,
                'artist' => 'Olgierd Ciszak',
                'print_number' => '005a',
            ]],
            'total' => 1, 'limit' => 100, 'offset' => 0,
        ], 200),
        'cdn.example.com/*' => Http::response('FAKEIMAGE', 200, ['Content-Type' => 'image/webp']),
    ]);
});

test('it imports cyberpunk cards from the API into the catalog', function () {
    $result = app(ImportCyberpunk::class)(withImages: true);

    expect($result['cards'])->toBe(1)
        ->and($result['images'])->toBe(1)
        ->and($result['sets'])->toContain('Welcome to Night City — Retail');

    $line = ProductLine::where('slug', 'cyberpunk')->first();
    expect($line)->not->toBeNull()->and($line->name)->toBe('Cyberpunk TCG');

    $card = CatalogItem::where('name', 'V — StreetKid')->first();
    expect($card)->not->toBeNull()
        ->and($card->number)->toBe('005a')
        ->and($card->attributes['faction'])->toBe('Red')
        ->and($card->attributes['type'])->toBe('Legend')
        ->and($card->attributes['cost'])->toBe(5)
        ->and($card->attributes['power'])->toBe(6)
        ->and($card->attributes['ram'])->toBe(2)
        ->and($card->attributes['classifications'])->toBe('Merc')
        ->and($card->external_ids['cyberpunk_id'])->toBe('81a8dec7-9541-4020-93e1-7d798a57dcbc')
        ->and($card->primary_image_path)->not->toBeNull();
});

test('a second run is a clean upsert (idempotent)', function () {
    app(ImportCyberpunk::class)(withImages: false);
    app(ImportCyberpunk::class)(withImages: false);

    expect(CatalogItem::where('name', 'V — StreetKid')->count())->toBe(1);
});

test('a dry run writes nothing', function () {
    $result = app(ImportCyberpunk::class)(dryRun: true);

    expect($result['cards'])->toBe(1)
        ->and(CatalogItem::where('name', 'V — StreetKid')->exists())->toBeFalse();
});
