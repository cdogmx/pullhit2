<?php

use App\Models\CatalogItem;
use App\Support\Ebay\EbayTitleResolver;

beforeEach(function () {
    $this->resolver = app(EbayTitleResolver::class);
});

test('it resolves a Lorcana-style "#NNN" title (no fraction in the title)', function () {
    $card = CatalogItem::factory()->create([
        'name' => 'Work Together',
        'number' => '165',
        'attributes' => ['language' => 'en'],
    ]);

    $r = $this->resolver->resolve(
        '2023 Disney Lorcana En 1-The First Chapter #165 Work Together Foil PSA 10',
        'en',
        0.75,
    );

    expect($r['number'])->toBe('165')
        ->and($r['reason'])->toBe('matched')
        ->and($r['item']->id)->toBe($card->id);
});

test('the # anchor takes the card number, not the leading year', function () {
    $card = CatalogItem::factory()->create([
        'name' => 'Anna - Braving the Storm',
        'number' => '218',
        'attributes' => ['language' => 'en'],
    ]);

    $r = $this->resolver->resolve(
        '2024 Disney Lorcana EN 11 #218 Anna - Braving the Storm PSA 10',
        'en',
        0.75,
    );

    // Must be 218 (the #-marked card number), never 2024 (the set year).
    expect($r['number'])->toBe('218')
        ->and($r['item']?->id)->toBe($card->id);
});

test('a title with no parseable number is still a no_number miss', function () {
    CatalogItem::factory()->create(['attributes' => ['language' => 'en']]);

    $r = $this->resolver->resolve(
        'Disney Lorcana We Could Be Immortals Enchanted Azurite Sea EN 6 PSA 10',
        'en',
        0.75,
    );

    expect($r['reason'])->toBe('no_number')
        ->and($r['number'])->toBeNull();
});
