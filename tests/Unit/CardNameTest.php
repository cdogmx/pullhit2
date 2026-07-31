<?php

use App\Support\Catalog\CardName;

test('it strips the collector number wherever it appears', function (string $raw, string $expected) {
    expect(CardName::clean($raw))->toBe($expected);
})->with([
    // Trailing, the common TCGCSV shape.
    ['Fomantis - 003/084', 'Fomantis'],
    // Before a printing parenthetical.
    ["Team Rocket's Dugtrio - 101/217 (Team Rocket)", "Team Rocket's Dugtrio (Team Rocket)"],
    // Promo set codes carry a hyphen after the slash.
    ['Pikachu - 001/XY-P', 'Pikachu'],
    ['Energy Retrieval - 003/SM-P', 'Energy Retrieval'],
    // Already clean.
    ['Sewaddle', 'Sewaddle'],
    ['Sewaddle (Master Ball Pattern)', 'Sewaddle (Master Ball Pattern)'],
]);

test('it leaves a subtitle alone', function (string $raw) {
    expect(CardName::clean($raw))->toBe($raw);
})->with([
    // Every Lorcana character is "Name - Subtitle"; no slash, so not a number.
    ['Mike Wazowski - Well-Rounded Entertainer'],
    ['Elsa - Snow Queen'],
    // A hyphenated subtitle must not be mistaken for a promo code either.
    ['Sisu - Daring Visitor'],
]);

test('it never returns an empty name', function () {
    expect(CardName::clean('- 003/084'))->toBe('- 003/084');
});
