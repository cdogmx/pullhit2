<?php

use App\Actions\Valuation\MaybeRefreshEbay;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($this->line)->create(['slug' => '30th-celebration', 'name' => '30th Celebration']);
    $this->due = app(MaybeRefreshEbay::class);

    $this->card = fn (string $refreshedAt) => CatalogItem::factory()->create([
        'product_line_id' => $this->line->id,
        'set_id' => $this->set->id,
        'ebay_refreshed_at' => $refreshedAt,
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Secret'],
    ]);
});

test('without a boost a set follows the global twelve hours', function () {
    expect($this->due->isDue(($this->card)(now()->subHours(2))))->toBeFalse()
        ->and($this->due->isDue(($this->card)(now()->subHours(13))))->toBeTrue();
});

test('a boosted set goes stale on its own clock', function () {
    $this->artisan('valuation:boost-set', ['set' => ['30th-celebration'], '--minutes' => 10])
        ->assertSuccessful();

    // Two hours is fresh for the catalog and ancient for a set in its first week.
    expect($this->due->isDue(($this->card)(now()->subHours(2))))->toBeTrue()
        ->and($this->due->isDue(($this->card)(now()->subMinutes(3))))->toBeFalse();
});

test('the boost lapses on its own rather than waiting to be remembered', function () {
    $this->artisan('valuation:boost-set', ['set' => ['30th-celebration'], '--minutes' => 10, '--days' => 7]);

    expect($this->due->isDue(($this->card)(now()->subHours(2))))->toBeTrue();

    $this->travel(8)->days();

    // Back to the global TTL — no one had to turn it off. Judged on a card
    // fetched two hours ago in the new present, not on one left behind by the
    // time travel, which would be stale under any rule.
    expect($this->due->isDue(($this->card)(now()->subHours(2))))->toBeFalse();
});

test('a boost can be ended early', function () {
    $this->artisan('valuation:boost-set', ['set' => ['30th-celebration'], '--minutes' => 10]);
    $this->artisan('valuation:boost-set', ['set' => ['30th-celebration'], '--clear' => true])
        ->assertSuccessful();

    expect($this->set->fresh()->refreshMinutes())->toBeNull()
        ->and($this->due->isDue(($this->card)(now()->subHours(2))))->toBeFalse();
});

test('a boost on one set leaves the rest of the catalog alone', function () {
    $other = Set::factory()->for($this->line)->create(['slug' => 'paldean-fates']);
    $elsewhere = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $other->id,
        'ebay_refreshed_at' => now()->subHours(2),
        'attributes' => ['language' => 'en', 'rarity' => 'Rare'],
    ]);

    $this->artisan('valuation:boost-set', ['set' => ['30th-celebration'], '--minutes' => 10]);

    expect($this->due->isDue($elsewhere))->toBeFalse();
});

test('an unknown slug boosts nothing', function () {
    $this->artisan('valuation:boost-set', ['set' => ['30th-celebration', 'no-such-set'], '--minutes' => 10])
        ->assertFailed();

    expect($this->set->fresh()->refreshMinutes())->toBeNull();
});

test('a card that has never been fetched is always due', function () {
    expect($this->due->isDue(($this->card)(now()->subMinutes(1))->forceFill(['ebay_refreshed_at' => null])))
        ->toBeTrue();
});
