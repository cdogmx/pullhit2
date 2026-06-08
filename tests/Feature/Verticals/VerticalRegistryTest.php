<?php

use App\Support\Verticals\VerticalRegistry;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->registry = app(VerticalRegistry::class);
});

test('the tcg vertical is registered with single and sealed item types', function () {
    expect($this->registry->has('tcg'))->toBeTrue();

    $tcg = $this->registry->get('tcg');
    expect($tcg->itemTypes())->toContain('single', 'sealed');
    expect($tcg->attributesFor('single'))->not->toBeEmpty();
});

test('valid pokemon single attributes pass validation', function () {
    $validated = $this->registry->validate('tcg', 'single', [
        'language' => 'en',
        'rarity' => 'Illustration Rare',
        'variant' => 'reverse_holo',
        'hp' => 60,
    ]);

    expect($validated['language'])->toBe('en');
});

test('missing a required facet fails validation', function () {
    $this->registry->validate('tcg', 'single', [
        // language (required) omitted
        'rarity' => 'Common',
        'variant' => 'normal',
    ]);
})->throws(ValidationException::class);

test('an unknown facet fails validation', function () {
    $this->registry->validate('tcg', 'single', [
        'language' => 'en',
        'rarity' => 'Common',
        'variant' => 'normal',
        'made_up_facet' => 'nope',
    ]);
})->throws(ValidationException::class);

test('an out-of-range enum value fails validation', function () {
    $this->registry->validate('tcg', 'single', [
        'language' => 'en',
        'rarity' => 'Common',
        'variant' => 'super_holo', // not an allowed variant
    ]);
})->throws(ValidationException::class);

test('a wrong-typed facet fails validation', function () {
    $this->registry->validate('tcg', 'single', [
        'language' => 'en',
        'rarity' => 'Common',
        'variant' => 'normal',
        'hp' => 'not-a-number',
    ]);
})->throws(ValidationException::class);

test('an unknown vertical throws', function () {
    $this->registry->validate('sports', 'single', []);
})->throws(InvalidArgumentException::class);
