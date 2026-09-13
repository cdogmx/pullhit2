<?php

use App\Actions\Valuation\MaybeRefreshEbay;
use App\Models\CatalogItem;
use App\Models\EbayScrapeJob;
use App\Support\Ebay\ScrapeAgentPresence;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    // The server's own fetcher is off; the browser agent is the path under test.
    config()->set('valuation.ebay.enabled', false);
    config()->set('valuation.ebay.view_refresh_hours', 12);

    $this->presence = app(ScrapeAgentPresence::class);
    $this->refresh = app(MaybeRefreshEbay::class);

    $this->card = CatalogItem::factory()->create([
        'ebay_refreshed_at' => null,
        'attributes' => ['language' => 'en', 'variant' => 'normal', 'rarity' => 'Rare'],
    ]);
});

test('viewing a stale card queues it for the agent and reports updating', function () {
    $this->presence->touch();

    expect(($this->refresh)($this->card))->toBeTrue();

    $job = EbayScrapeJob::where('catalog_item_id', $this->card->id)->firstOrFail();

    expect($job->status)->toBe(EbayScrapeJob::STATUS_PENDING)
        ->and($job->url)->toContain('LH_Sold=1')
        // Ahead of anything the routine top-up queued: someone is reading this
        // card right now.
        ->and($job->priority)->toBe(100);
});

test('nothing is promised when the agent is not running', function () {
    // The extension is a browser on a desk and may simply be closed. A spinner
    // for work nobody is doing is worse than no spinner.
    expect(($this->refresh)($this->card))->toBeFalse()
        ->and(EbayScrapeJob::count())->toBe(0);
});

test('an agent that has gone quiet counts as not running', function () {
    $this->presence->touch();

    $this->travel(11)->minutes();

    expect($this->presence->isLive())->toBeFalse()
        ->and(($this->refresh)($this->card))->toBeFalse()
        ->and(EbayScrapeJob::count())->toBe(0);
});

test('a second view does not queue the same card twice', function () {
    $this->presence->touch();

    expect(($this->refresh)($this->card))->toBeTrue()
        // Still updating, still true — but still one job.
        ->and(($this->refresh)($this->card))->toBeTrue()
        ->and(EbayScrapeJob::where('catalog_item_id', $this->card->id)->count())->toBe(1);
});

test('a card already being fetched is not queued again', function () {
    $this->presence->touch();

    EbayScrapeJob::factory()->create([
        'catalog_item_id' => $this->card->id,
        'status' => EbayScrapeJob::STATUS_LEASED,
        'leased_until' => now()->addMinutes(5),
    ]);

    expect(($this->refresh)($this->card))->toBeTrue()
        ->and(EbayScrapeJob::where('catalog_item_id', $this->card->id)->count())->toBe(1);
});

test('a card finished recently is left alone', function () {
    $this->presence->touch();
    $this->card->forceFill(['ebay_refreshed_at' => now()->subHour()])->save();

    expect(($this->refresh)($this->card->fresh()))->toBeFalse()
        ->and(EbayScrapeJob::count())->toBe(0);
});

test('a rarity we decline to spend on is still declined', function () {
    $this->presence->touch();
    config()->set('valuation.ebay.skip_rarities', ['Common']);

    $common = CatalogItem::factory()->create([
        'ebay_refreshed_at' => null,
        'attributes' => ['language' => 'en', 'variant' => 'normal', 'rarity' => 'Common'],
    ]);

    expect(($this->refresh)($common))->toBeFalse()
        ->and(EbayScrapeJob::count())->toBe(0);
});

test('the agent claiming work counts as a heartbeat', function () {
    config()->set('services.scrape_agent.token', 'test-token');

    expect($this->presence->isLive())->toBeFalse();

    $this->postJson('/api/agent/claim', ['limit' => 1], ['Authorization' => 'Bearer test-token'])
        ->assertOk();

    expect($this->presence->isLive())->toBeTrue();
});

test('the status endpoint reports when the agent was last heard from', function () {
    config()->set('services.scrape_agent.token', 'test-token');
    $this->presence->touch();

    $this->getJson('/api/agent/status', ['Authorization' => 'Bearer test-token'])
        ->assertOk()
        ->assertJsonPath('agent_last_seen', fn ($seen) => $seen !== null);
});

test('with the server fetcher switched on, nothing is queued for the agent', function () {
    // Two transports must not both fetch the same card.
    config()->set('valuation.ebay.enabled', true);
    $this->presence->touch();

    expect(($this->refresh)($this->card))->toBeTrue()
        ->and(EbayScrapeJob::count())->toBe(0);
});
