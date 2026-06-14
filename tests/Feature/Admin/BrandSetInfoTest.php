<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
});

test('the brands page lists product lines', function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg', 'name' => 'TCG']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    Set::factory()->for($line)->create();

    $this->actingAs($this->admin)->get('/admin/brands')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/brands')
            ->where('brands.0.slug', 'pokemon'),
        );
});

test('an admin can set a brand logo and description', function () {
    $line = ProductLine::factory()->create(['name' => 'Pokémon']);

    $this->actingAs($this->admin)->patch("/admin/brands/{$line->id}", [
        'name' => 'Pokémon TCG',
        'description' => 'Gotta catch them all.',
        'logo_url' => 'https://img.test/pokemon-logo.png',
    ])->assertRedirect();

    $line->refresh();
    expect($line->name)->toBe('Pokémon TCG')
        ->and($line->description)->toBe('Gotta catch them all.')
        ->and($line->logo_path)->toBe('https://img.test/pokemon-logo.png');
});

test('an admin can set a set logo, description, and info', function () {
    $set = Set::factory()->create(['name' => 'Surging Sparks']);

    $this->actingAs($this->admin)->patch("/admin/sets/{$set->id}", [
        'name' => 'Surging Sparks',
        'code' => 'SSP',
        'released_at' => '2024-11-08',
        'description' => 'The Pikachu ex set.',
        'logo_url' => 'https://img.test/ssp.png',
    ])->assertRedirect();

    $set->refresh();
    expect($set->code)->toBe('SSP')
        ->and($set->description)->toBe('The Pikachu ex set.')
        ->and($set->logo_path)->toBe('https://img.test/ssp.png')
        ->and($set->released_at->toDateString())->toBe('2024-11-08');
});

test('non-admins cannot edit brands or sets', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $line = ProductLine::factory()->create();
    $set = Set::factory()->create();

    $this->actingAs($user)->patch("/admin/brands/{$line->id}", ['name' => 'x'])->assertForbidden();
    $this->actingAs($user)->patch("/admin/sets/{$set->id}", ['name' => 'x'])->assertForbidden();
});

test('a set logo overrides the card thumbnail on browse tiles', function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon']);
    $set = Set::factory()->for($line)->create([
        'slug' => 'surging-sparks', 'language' => 'en',
        'logo_path' => 'https://img.test/ssp-logo.png',
    ]);
    CatalogItem::factory()->for($vertical)->for($line)->for($set)
        ->create(['primary_image_path' => 'https://img.test/card.png']);

    $this->get('/browse?product_line=pokemon')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tiles.0.thumb', 'https://img.test/ssp-logo.png'),
        );
});
