<?php

use App\Support\Catalog\Subsets;

test('it splits every recognized gallery suffix from the parent name', function (string $name, ?string $parent, ?string $suffix) {
    expect(Subsets::split($name))->toBe([$parent, $suffix]);
})->with([
    ['Astral Radiance Trainer Gallery', 'Astral Radiance', 'Trainer Gallery'],
    ['Crown Zenith Galarian Gallery', 'Crown Zenith', 'Galarian Gallery'],
    ['Hidden Fates Shiny Vault', 'Hidden Fates', 'Shiny Vault'],
    ['Shining Fates Shiny Vault', 'Shining Fates', 'Shiny Vault'],
    // A plain expansion has no suffix and stays whole.
    ['Hidden Fates', null, null],
    ['Surging Sparks', null, null],
]);
