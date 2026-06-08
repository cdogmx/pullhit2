<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\Set;
use App\Models\Vertical;
use Database\Seeders\GradingCompaniesSeeder;
use Database\Seeders\PokemonCatalogSeeder;

test('the grading companies seeder is idempotent', function () {
    $this->seed(GradingCompaniesSeeder::class);
    $this->seed(GradingCompaniesSeeder::class);

    expect(GradingCompany::count())->toBe(6);
    expect(GradingCompany::where('slug', 'bgs')->first()->supports_pristine_black_label)->toBeTrue();
});

test('the pokemon catalog seeder creates the vertical, set, and items', function () {
    $this->seed(PokemonCatalogSeeder::class);

    expect(Vertical::where('slug', 'tcg')->exists())->toBeTrue();

    $set = Set::where('code', 'MEW')->first();
    expect($set)->not->toBeNull()
        ->and($set->language)->toBe('en');

    expect(CatalogItem::where('item_type', ItemType::Single)->where('language', 'en')->exists())->toBeTrue();
    expect(CatalogItem::where('item_type', ItemType::Sealed)->exists())->toBeTrue();
});

test('re-running the pokemon catalog seeder dedups items', function () {
    $this->seed(PokemonCatalogSeeder::class);
    $this->seed(PokemonCatalogSeeder::class);

    expect(CatalogItem::count())->toBe(2);
    expect(Vertical::where('slug', 'tcg')->count())->toBe(1);
});
