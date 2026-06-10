<?php

use App\Support\Catalog\PokemonCardMapper;

test('it maps each TCGplayer finish to a variant with its price anchor', function () {
    $card = [
        'id' => 'sv8-1', 'name' => 'Pikachu', 'number' => '1', 'rarity' => 'Common',
        'artist' => 'Ken Sugimori', 'hp' => '60', 'types' => ['Lightning'],
        'images' => ['large' => 'https://images.pokemontcg.io/sv8/1_hires.png'],
        'tcgplayer' => ['prices' => [
            'normal' => ['market' => 0.50, 'low' => 0.10, 'high' => 1.00],
            'reverseHolofoil' => ['market' => 2.00, 'low' => 1.00, 'high' => 5.00],
        ]],
    ];

    $items = (new PokemonCardMapper)->map($card);
    $byVariant = collect($items)->keyBy(fn ($i) => $i->attributes['variant']);

    expect($items)->toHaveCount(2)
        ->and($byVariant['normal']->anchorCents)->toBe(50)
        ->and($byVariant['reverse_holo']->anchorCents)->toBe(200)
        ->and($byVariant['normal']->attributes)->toMatchArray([
            'language' => 'en', 'rarity' => 'Common', 'variant' => 'normal',
            'hp' => 60, 'type' => 'Lightning', 'illustrator' => 'Ken Sugimori',
        ])
        ->and($byVariant['normal']->externalIds['ptcgio_id'])->toBe('sv8-1')
        ->and($byVariant['normal']->externalIds['ptcgio_image'])->toContain('1_hires.png');
});

test('a card with no prices maps to one normal item with no anchor', function () {
    $items = (new PokemonCardMapper)->map([
        'id' => 'sv8-200', 'name' => 'Basic Energy', 'number' => '200', 'rarity' => 'Common',
    ]);

    expect($items)->toHaveCount(1)
        ->and($items[0]->attributes['variant'])->toBe('normal')
        ->and($items[0]->anchorCents)->toBe(0);
});
