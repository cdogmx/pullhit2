<?php

use App\Support\Catalog\CardDisplayName;

test('display names distinguish printings of the same card', function () {
    expect(CardDisplayName::for('Charizard', ['variant' => 'holo', 'edition' => 'unlimited']))->toBe('Charizard (Unlimited)');
    expect(CardDisplayName::for('Charizard', ['variant' => 'holo', 'edition' => 'first_edition']))->toBe('Charizard (1st Edition)');
    expect(CardDisplayName::for('Charizard', ['variant' => 'holo', 'edition' => 'shadowless']))->toBe('Charizard (Shadowless)');
    expect(CardDisplayName::for('Pikachu', ['variant' => 'reverse_holo']))->toBe('Pikachu (Reverse Holo)');

    // A finish (error/promo) is the label; the Unlimited edition is dropped beside it.
    expect(CardDisplayName::for('Charizard', ['variant' => 'holo', 'edition' => 'unlimited', 'finish' => 'black_dot_error']))->toBe('Charizard (Black Dot Error)');
    expect(CardDisplayName::for('Charizard', ['finish' => '1999_2000']))->toBe('Charizard (1999-2000)');
    expect(CardDisplayName::for('Charizard', ['edition' => 'first_edition', 'finish' => 'ghost_stamp']))->toBe('Charizard (1st Edition, Ghost Stamp)');

    // A plain modern card gets no qualifier.
    expect(CardDisplayName::for('Pikachu', ['variant' => 'holo']))->toBe('Pikachu');
    expect(CardDisplayName::for('Pikachu', ['variant' => 'normal']))->toBe('Pikachu');
});
