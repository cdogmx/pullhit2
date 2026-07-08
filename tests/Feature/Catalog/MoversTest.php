<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

function moverCard(ProductLine $line, string $name, float $trend, int $median = 5000): CatalogItem
{
    $item = CatalogItem::factory()->for($line)->create([
        'name' => $name,
        'primary_image_path' => "images/{$name}.jpg",
    ]);

    MarketValue::factory()->for($item, 'catalogItem')->create([
        'grading_company_id' => null,
        'is_estimated' => false,
        'median' => $median,
        'n_sales' => 8,
        'trend_30d' => $trend,
    ]);

    return $item;
}

beforeEach(function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
});

test('it splits movers into gainers (desc) and losers (asc)', function () {
    moverCard($this->line, 'Big Gainer', 40.0);
    moverCard($this->line, 'Small Gainer', 5.0);
    moverCard($this->line, 'Big Loser', -35.0);
    moverCard($this->line, 'Small Loser', -4.0);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('movers')
            ->has('gainers', 2)
            ->has('losers', 2)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'Big Gainer')) // largest rise first
            ->where('gainers.0.trend', fn ($t) => abs($t - 40) < 0.01)
            ->where('losers.0.name', fn ($n) => str_contains($n, 'Big Loser')) // largest drop first
            ->where('losers.0.trend', fn ($t) => abs($t + 35) < 0.01));
});

test('it computes the signed dollar change from the percent trend', function () {
    // +25% over the window on a $50 value → prior ≈ $40, change ≈ +$10 (1000c).
    moverCard($this->line, 'Riser', 25.0, 5000);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('gainers.0.change', fn ($c) => abs($c - 1000) <= 1));
});

test('it excludes estimated, penny, thin, and flat values', function () {
    // Estimated → excluded.
    $est = CatalogItem::factory()->for($this->line)->create(['primary_image_path' => 'a.jpg']);
    MarketValue::factory()->for($est, 'catalogItem')->create(['is_estimated' => true, 'median' => 5000, 'n_sales' => 8, 'trend_30d' => 30, 'grading_company_id' => null]);

    // Penny card (below the floor) → excluded.
    moverCard($this->line, 'Penny', 90.0, 100);

    // Too few sales → excluded.
    $thin = CatalogItem::factory()->for($this->line)->create(['primary_image_path' => 'b.jpg']);
    MarketValue::factory()->for($thin, 'catalogItem')->create(['is_estimated' => false, 'median' => 5000, 'n_sales' => 1, 'trend_30d' => 30, 'grading_company_id' => null]);

    // Flat (0% trend) → excluded from both.
    moverCard($this->line, 'Flat', 0.0);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('gainers', 0)
            ->has('losers', 0));
});

test('graded values are never ranked (ungraded only)', function () {
    $item = CatalogItem::factory()->for($this->line)->create(['primary_image_path' => 'g.jpg']);
    $company = GradingCompany::factory()->create();
    MarketValue::factory()->for($item, 'catalogItem')->create([
        'grading_company_id' => $company->id,
        'is_estimated' => false, 'median' => 50000, 'n_sales' => 8, 'trend_30d' => 50,
    ]);

    $this->get('/movers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('gainers', 0));
});

test('the line filter scopes movers to one product line', function () {
    moverCard($this->line, 'Pokemon Riser', 30.0);

    $other = ProductLine::factory()->for(Vertical::factory()->create(['slug' => 'tcg2']))
        ->create(['slug' => 'one-piece', 'name' => 'One Piece']);
    moverCard($other, 'One Piece Riser', 45.0);

    $this->get('/movers?line=pokemon')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('line', 'pokemon')
            ->has('gainers', 1)
            ->where('gainers.0.name', fn ($n) => str_contains($n, 'Pokemon Riser')));
});
