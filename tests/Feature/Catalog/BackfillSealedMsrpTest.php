<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\SealedMsrpResearcher;

/** A researcher stub returning a canned result per call (no HTTP). */
function fakeMsrpResearcher(?array $result): SealedMsrpResearcher
{
    return new class($result) extends SealedMsrpResearcher
    {
        public function __construct(private ?array $result) {}

        public function research(array $product): ?array
        {
            return $this->result;
        }
    };
}

beforeEach(function () {
    $this->pokemon = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->pokemon->id,
        'name' => 'Crown Zenith',
        'released_at' => '2023-01-20',
    ]);
});

test('it fills a missing MSRP with the sourced figure and its citation', function () {
    $box = CatalogItem::factory()->sealed()->create([
        'product_line_id' => $this->pokemon->id,
        'set_id' => $this->set->id,
        'name' => 'Crown Zenith Booster Box',
        'msrp' => null,
        'released_at' => null,
    ]);

    $this->app->instance(SealedMsrpResearcher::class, fakeMsrpResearcher([
        'msrp_cents' => 16164,
        'source' => 'https://www.pokemoncenter.com/product/crown-zenith-box',
        'note' => 'Pokémon Center listing',
        'confidence' => 0.9,
    ]));

    $this->artisan('sealed:backfill-msrp', ['--limit' => 5])->assertOk();

    $box->refresh();
    expect($box->msrp)->toBe(16164)
        ->and($box->msrp_source)->toBe('https://www.pokemoncenter.com/product/crown-zenith-box')
        // Release date inherited from the parent set (free, no citation needed).
        ->and($box->released_at?->toDateString())->toBe('2023-01-20');
});

test('it never overwrites an existing MSRP without --force', function () {
    $box = CatalogItem::factory()->sealed()->create([
        'product_line_id' => $this->pokemon->id,
        'set_id' => $this->set->id,
        'msrp' => 9999,
    ]);

    $this->app->instance(SealedMsrpResearcher::class, fakeMsrpResearcher([
        'msrp_cents' => 16164, 'source' => 'https://x', 'note' => null, 'confidence' => 0.99,
    ]));

    $this->artisan('sealed:backfill-msrp', ['--limit' => 5])->assertOk();

    expect($box->refresh()->msrp)->toBe(9999); // untouched
});

test('it leaves the MSRP null when nothing credible is found', function () {
    $box = CatalogItem::factory()->sealed()->create([
        'product_line_id' => $this->pokemon->id,
        'set_id' => $this->set->id,
        'msrp' => null,
    ]);

    $this->app->instance(SealedMsrpResearcher::class, fakeMsrpResearcher(null));

    $this->artisan('sealed:backfill-msrp', ['--limit' => 5])->assertOk();

    expect($box->refresh()->msrp)->toBeNull();
});

test('a not-found product is marked tried so a re-run does not re-research it', function () {
    $box = CatalogItem::factory()->sealed()->create([
        'product_line_id' => $this->pokemon->id,
        'set_id' => $this->set->id,
        'msrp' => null,
    ]);

    $this->app->instance(SealedMsrpResearcher::class, fakeMsrpResearcher(null));
    $this->artisan('sealed:backfill-msrp', ['--limit' => 5])->assertOk();

    $box->refresh();
    expect($box->msrp)->toBeNull()
        ->and($box->msrp_source)->toBe('not_found');

    // A re-run must report zero targets — the tried product is excluded, so a
    // batch loop makes progress instead of looping on the same un-findable item.
    $this->artisan('sealed:backfill-msrp', ['--limit' => 5])
        ->expectsOutputToContain('No sealed products to research')
        ->assertOk();
});

test('it skips low-confidence results below the threshold', function () {
    $box = CatalogItem::factory()->sealed()->create([
        'product_line_id' => $this->pokemon->id,
        'set_id' => $this->set->id,
        'msrp' => null,
    ]);

    $this->app->instance(SealedMsrpResearcher::class, fakeMsrpResearcher([
        'msrp_cents' => 16164, 'source' => 'https://x', 'note' => null, 'confidence' => 0.3,
    ]));

    $this->artisan('sealed:backfill-msrp', ['--limit' => 5, '--min-confidence' => 0.5])->assertOk();

    expect($box->refresh()->msrp)->toBeNull();
});

test('it only targets the priority box types by default', function () {
    // A tin is not a priority type, so the default run must ignore it.
    $tin = CatalogItem::factory()->sealed()->create([
        'product_line_id' => $this->pokemon->id,
        'set_id' => $this->set->id,
        'attributes' => ['sealed_type' => 'tin', 'language' => 'en'],
        'msrp' => null,
    ]);

    $this->app->instance(SealedMsrpResearcher::class, fakeMsrpResearcher([
        'msrp_cents' => 2499, 'source' => 'https://x', 'note' => null, 'confidence' => 0.9,
    ]));

    $this->artisan('sealed:backfill-msrp', ['--limit' => 5])->assertOk();
    expect($tin->refresh()->msrp)->toBeNull();

    // But an explicit --type=tin picks it up.
    $this->artisan('sealed:backfill-msrp', ['--type' => ['tin'], '--limit' => 5])->assertOk();
    expect($tin->refresh()->msrp)->toBe(2499);
});
