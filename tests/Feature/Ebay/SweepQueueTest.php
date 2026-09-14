<?php

use App\Models\CatalogItem;
use App\Models\EbayScrapeJob;
use App\Models\SaleObservation;
use App\Models\Set;

beforeEach(function () {
    config()->set('services.scrape_agent.token', 'test-token');
    config()->set('valuation.ebay.sweep.enabled', true);
    config()->set('valuation.ebay.sweep.min_score', 0.75);
    config()->set('valuation.ebay.sweep.searches', [
        [
            'label' => 'pokemon-psa10', 'language' => 'en', 'line' => 'pokemon',
            'interval_minutes' => 20,
            'url' => 'https://www.ebay.com/sch/i.html?_nkw=pokemon+psa+10&LH_Sold=1&LH_Complete=1',
        ],
        [
            'label' => 'lorcana-psa10', 'language' => 'en', 'line' => 'lorcana',
            'interval_minutes' => 120,
            'url' => 'https://www.ebay.com/sch/i.html?_nkw=disney+lorcana+psa+10&LH_Sold=1&LH_Complete=1',
        ],
    ]);

    $this->headers = ['Authorization' => 'Bearer test-token'];
});

test('each configured search is queued once', function () {
    $this->artisan('ebay:enqueue-sweeps')->assertSuccessful();

    $jobs = EbayScrapeJob::where('kind', EbayScrapeJob::KIND_SWEEP)->get();

    expect($jobs)->toHaveCount(2)
        ->and($jobs->pluck('label')->sort()->values()->all())->toBe(['lorcana-psa10', 'pokemon-psa10'])
        // A sweep belongs to no card until its titles are resolved.
        ->and($jobs->pluck('catalog_item_id')->filter())->toBeEmpty()
        // Above the routine per-card top-up, below a card being viewed.
        ->and($jobs->pluck('priority')->unique()->all())->toBe([50]);
});

test('a search already queued is not queued again', function () {
    // Running every five minutes must not stack five copies of a twenty-minute
    // search while the agent is still working through the first.
    $this->artisan('ebay:enqueue-sweeps')->assertSuccessful();
    $this->artisan('ebay:enqueue-sweeps')->assertSuccessful();

    expect(EbayScrapeJob::where('kind', EbayScrapeJob::KIND_SWEEP)->count())->toBe(2);
});

test('a search is not queued again until its own interval has passed', function () {
    EbayScrapeJob::factory()->create([
        'kind' => EbayScrapeJob::KIND_SWEEP,
        'label' => 'pokemon-psa10',
        'catalog_item_id' => null,
        'status' => EbayScrapeJob::STATUS_DONE,
        'completed_at' => now()->subMinutes(5),
    ]);

    $this->artisan('ebay:enqueue-sweeps')->assertSuccessful();

    expect(EbayScrapeJob::outstanding()->where('label', 'pokemon-psa10')->count())->toBe(0)
        // The other search has never run, so it goes now.
        ->and(EbayScrapeJob::outstanding()->where('label', 'lorcana-psa10')->count())->toBe(1);

    $this->travel(21)->minutes();
    $this->artisan('ebay:enqueue-sweeps')->assertSuccessful();

    expect(EbayScrapeJob::outstanding()->where('label', 'pokemon-psa10')->count())->toBe(1);
});

test('the interval runs from when the agent finished, not from the last tick', function () {
    // A stopped agent must not silently burn a search's turn: the clock starts
    // when the work was actually done.
    EbayScrapeJob::factory()->create([
        'kind' => EbayScrapeJob::KIND_SWEEP,
        'label' => 'pokemon-psa10',
        'catalog_item_id' => null,
        'status' => EbayScrapeJob::STATUS_DONE,
        'completed_at' => now()->subHours(3),
    ]);

    $this->artisan('ebay:enqueue-sweeps')->assertSuccessful();

    expect(EbayScrapeJob::outstanding()->where('label', 'pokemon-psa10')->count())->toBe(1);
});

test('--force ignores the interval', function () {
    EbayScrapeJob::factory()->create([
        'kind' => EbayScrapeJob::KIND_SWEEP, 'label' => 'pokemon-psa10',
        'catalog_item_id' => null, 'status' => EbayScrapeJob::STATUS_DONE,
        'completed_at' => now(),
    ]);

    $this->artisan('ebay:enqueue-sweeps', ['--force' => true])->assertSuccessful();

    expect(EbayScrapeJob::outstanding()->where('label', 'pokemon-psa10')->count())->toBe(1);
});

test('a swept page is resolved back to cards and stored', function () {
    $set = Set::factory()->create(['name' => 'Obsidian Flames', 'language' => 'en']);
    $card = CatalogItem::factory()->create([
        'set_id' => $set->id,
        'name' => 'Charizard ex',
        'number' => '223',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    $job = EbayScrapeJob::factory()->create([
        'kind' => EbayScrapeJob::KIND_SWEEP,
        'label' => 'pokemon-psa10',
        'catalog_item_id' => null,
    ]);

    $response = $this->postJson('/api/agent/result', [
        'id' => $job->id,
        'html' => file_get_contents(base_path('tests/Fixtures/ebay-sold-search.html')),
    ], $this->headers)->assertOk();

    expect($response->json('status'))->toBe('ok')
        ->and($response->json('fetched'))->toBeGreaterThan(0)
        ->and($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_DONE)
        ->and($job->fresh()->note)->toContain('matched');
});

test('a sweep whose search no longer exists fails rather than guessing', function () {
    $job = EbayScrapeJob::factory()->create([
        'kind' => EbayScrapeJob::KIND_SWEEP,
        'label' => 'a-label-nobody-configured',
        'catalog_item_id' => null,
    ]);

    $this->postJson('/api/agent/result', [
        'id' => $job->id,
        'html' => file_get_contents(base_path('tests/Fixtures/ebay-sold-search.html')),
    ], $this->headers)->assertOk()->assertJsonPath('status', 'failed');

    expect($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_FAILED)
        ->and(SaleObservation::count())->toBe(0);
});

test('a sign-in wall on a sweep is a block, not an empty sweep', function () {
    $job = EbayScrapeJob::factory()->create([
        'kind' => EbayScrapeJob::KIND_SWEEP,
        'label' => 'pokemon-psa10',
        'catalog_item_id' => null,
        'attempts' => 3,
    ]);

    $this->postJson('/api/agent/result', [
        'id' => $job->id,
        'html' => '<html><head><title>Sign in or Register | eBay</title></head><body></body></html>',
    ], $this->headers)->assertOk()->assertJsonPath('status', EbayScrapeJob::STATUS_BLOCKED);
});

test('the queue is skipped entirely when the sweep is disabled', function () {
    config()->set('valuation.ebay.sweep.enabled', false);

    $this->artisan('ebay:enqueue-sweeps')->assertSuccessful();

    expect(EbayScrapeJob::count())->toBe(0);
});

test('a sweep job reaches the agent with a readable label', function () {
    EbayScrapeJob::factory()->create([
        'kind' => EbayScrapeJob::KIND_SWEEP,
        'label' => 'pokemon-psa10',
        'catalog_item_id' => null,
    ]);

    $this->postJson('/api/agent/claim', ['limit' => 1], $this->headers)
        ->assertOk()
        ->assertJsonPath('jobs.0.label', 'sweep: pokemon-psa10');
});
