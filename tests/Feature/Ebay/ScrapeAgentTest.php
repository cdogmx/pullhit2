<?php

use App\Models\CatalogItem;
use App\Models\EbayScrapeJob;
use App\Models\MarketValue;
use App\Models\SaleObservation;
use App\Models\Set;

beforeEach(function () {
    config()->set('services.scrape_agent.token', 'test-token');

    $this->item = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4']);
    $this->headers = ['Authorization' => 'Bearer test-token'];
});

/** A real eBay sold page, captured live. */
function soldHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/ebay-sold-search.html'));
}

/** A card the queue should consider: valued, with its rarity pinned. */
function enqueueableCard(?DateTimeInterface $refreshedAt, ?string $rarity = 'Rare', int $popularity = 0): CatalogItem
{
    $item = CatalogItem::factory()->create([
        'ebay_refreshed_at' => $refreshedAt,
        'popularity' => $popularity,
        'attributes' => array_filter([
            'language' => 'en', 'variant' => 'normal', 'rarity' => $rarity,
        ], fn ($v) => $v !== null),
    ]);

    MarketValue::factory()->create(['catalog_item_id' => $item->id]);

    return $item;
}

test('it refuses a request with no token, a wrong token, or none configured', function () {
    EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id]);

    $this->postJson('/api/agent/claim')->assertUnauthorized();
    $this->postJson('/api/agent/claim', [], ['Authorization' => 'Bearer nope'])->assertUnauthorized();

    // An unset token must never read as "open" — that would publish the queue.
    config()->set('services.scrape_agent.token', '');
    $this->postJson('/api/agent/claim', [], $this->headers)->assertStatus(503);
});

test('claiming leases the job so a second agent cannot take it', function () {
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id]);

    $first = $this->postJson('/api/agent/claim', ['limit' => 5], $this->headers)->assertOk();

    expect($first->json('jobs'))->toHaveCount(1)
        ->and($first->json('jobs.0.id'))->toBe($job->id);

    $second = $this->postJson('/api/agent/claim', ['limit' => 5], $this->headers)->assertOk();

    expect($second->json('jobs'))->toBeEmpty()
        ->and($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_LEASED)
        ->and($job->fresh()->attempts)->toBe(1);
});

test('an expired lease returns the job to the queue', function () {
    // The agent is a browser on a desk; it can close mid-job and never report.
    EbayScrapeJob::factory()->create([
        'catalog_item_id' => $this->item->id,
        'status' => EbayScrapeJob::STATUS_LEASED,
        'leased_until' => now()->subMinute(),
    ]);

    expect($this->postJson('/api/agent/claim', [], $this->headers)->json('jobs'))->toHaveCount(1);
});

test('higher priority runs first', function () {
    $routine = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id, 'priority' => 0]);
    $urgent = EbayScrapeJob::factory()->create([
        'catalog_item_id' => CatalogItem::factory(), 'priority' => 10,
    ]);

    $jobs = $this->postJson('/api/agent/claim', ['limit' => 2], $this->headers)->json('jobs');

    expect($jobs[0]['id'])->toBe($urgent->id)
        ->and($jobs[1]['id'])->toBe($routine->id);
});

test('posting a real sold page ingests comps and completes the job', function () {
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id]);

    $response = $this->postJson('/api/agent/result', [
        'id' => $job->id, 'html' => soldHtml(),
    ], $this->headers)->assertOk();

    expect($response->json('candidates'))->toBeGreaterThan(0)
        ->and($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_DONE)
        ->and($job->fresh()->completed_at)->not->toBeNull()
        // The card was actually refreshed, which is the point of all of this.
        ->and($this->item->fresh()->ebay_refreshed_at)->not->toBeNull();
});

test('a sign-in wall is recorded as a block, never as "this card has no comps"', function () {
    // The failure that matters. A wall is HTTP 200 with a real page, and
    // believing it would wipe a card's comps and stamp it fresh for hours.
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id, 'attempts' => 3]);
    $before = $this->item->ebay_refreshed_at;

    $response = $this->postJson('/api/agent/result', [
        'id' => $job->id,
        'html' => '<html><head><title>Sign in or Register | eBay</title></head><body>nothing</body></html>',
    ], $this->headers)->assertOk();

    expect($response->json('status'))->toBe(EbayScrapeJob::STATUS_BLOCKED)
        ->and($response->json('should_pause'))->toBeTrue()
        ->and($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_BLOCKED)
        ->and($this->item->fresh()->ebay_refreshed_at)->toEqual($before);
});

test('the captcha splash is a block too', function () {
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id, 'attempts' => 3]);

    $response = $this->postJson('/api/agent/result', [
        'id' => $job->id,
        'html' => '<html><head><title>Security Measure | eBay</title></head><body></body></html>',
    ], $this->headers)->assertOk();

    expect($response->json('status'))->toBe(EbayScrapeJob::STATUS_BLOCKED);
});

test('a sign-in link in the nav does not make an ordinary page a wall', function () {
    // Every real eBay page carries signin.ebay.com links in its header. Matching
    // on those is what turned a parser bug into a phantom block once already, so
    // a normal page carrying one has to come back as a result.
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id]);

    $page = '<html><head><title>Charizard for sale | eBay</title></head><body>'
        .'<a href="https://signin.ebay.com/ws/eBayISAPI.dll?SignIn">Sign in</a>'
        .'<h3 class="srp-save-null-search__heading">No exact matches found</h3>'
        .'</body></html>';

    $this->postJson('/api/agent/result', ['id' => $job->id, 'html' => $page], $this->headers)
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    expect($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_DONE);
});

test('a real captured sold page ingests through the agent path', function () {
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id]);

    $this->postJson('/api/agent/result', ['id' => $job->id, 'html' => soldHtml()], $this->headers)
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

test('a page eBay declares empty is a real zero and completes the job', function () {
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id]);

    $this->postJson('/api/agent/result', [
        'id' => $job->id,
        'html' => '<html><head><title>x for sale | eBay</title></head><body>'
            .'<h3 class="srp-save-null-search__heading">No exact matches found</h3></body></html>',
    ], $this->headers)->assertOk()->assertJsonPath('comps', 0);

    expect($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_DONE)
        ->and($job->fresh()->note)->toBe('eBay reported no matches');
});

test('a failure is requeued until it has had enough tries', function () {
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id, 'attempts' => 1]);

    $this->postJson('/api/agent/result', [
        'id' => $job->id, 'status' => 'failed', 'note' => 'timed out',
    ], $this->headers)->assertOk()->assertJsonPath('status', 'requeued');

    expect($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_PENDING);

    $job->update(['attempts' => 3]);

    $this->postJson('/api/agent/result', [
        'id' => $job->id, 'status' => 'failed', 'note' => 'timed out again',
    ], $this->headers)->assertOk()->assertJsonPath('status', 'failed');

    expect($job->fresh()->status)->toBe(EbayScrapeJob::STATUS_FAILED);
});

test('reporting the same job twice does not ingest it twice', function () {
    $job = EbayScrapeJob::factory()->create(['catalog_item_id' => $this->item->id]);

    $this->postJson('/api/agent/result', ['id' => $job->id, 'html' => soldHtml()], $this->headers)->assertOk();
    $after = SaleObservation::where('catalog_item_id', $this->item->id)->count();

    $this->postJson('/api/agent/result', ['id' => $job->id, 'html' => soldHtml()], $this->headers)
        ->assertOk()
        ->assertJsonPath('status', 'already done');

    expect(SaleObservation::where('catalog_item_id', $this->item->id)->count())->toBe($after);
});

test('status reports the queue depth', function () {
    EbayScrapeJob::factory()->count(3)->create(['catalog_item_id' => $this->item->id]);
    EbayScrapeJob::factory()->create([
        'catalog_item_id' => $this->item->id,
        'status' => EbayScrapeJob::STATUS_DONE,
        'comps_found' => 7,
        'completed_at' => now(),
    ]);

    $this->getJson('/api/agent/status', $this->headers)
        ->assertOk()
        ->assertJsonPath('outstanding', 3)
        ->assertJsonPath('done_today', 1)
        ->assertJsonPath('comps_today', 7);
});

test('the enqueue command queues the stalest valued cards and does not double up', function () {
    $stale = enqueueableCard(now()->subDays(30));
    $fresh = enqueueableCard(now());
    $never = enqueueableCard(null);

    $this->artisan('ebay:enqueue-sold', ['--limit' => 10])->assertSuccessful();

    $queued = EbayScrapeJob::orderBy('id')->pluck('catalog_item_id')->all();

    expect($queued)->toContain($never->id)
        ->and($queued)->toContain($stale->id)
        ->and($queued)->not->toContain($fresh->id)
        // Never-fetched leads merely-old when nothing has been viewed.
        ->and($queued[0])->toBe($never->id);

    // Re-running tops the queue up rather than stacking a second copy.
    $this->artisan('ebay:enqueue-sold', ['--limit' => 10])->assertSuccessful();

    expect(EbayScrapeJob::count())->toBe(count($queued));
});

test('naming a set queues that set, values or no values', function () {
    // A set that was just imported has no market value on any of its cards, and
    // the unvalued skip would queue none of them — which is the opposite of what
    // "queue this set" asks for.
    $set = Set::factory()->create(['slug' => '30th-celebration-promos', 'name' => '30th Celebration Promos']);

    $unvalued = CatalogItem::factory()->create([
        'set_id' => $set->id,
        'ebay_refreshed_at' => null,
        'attributes' => ['language' => 'en', 'variant' => 'normal', 'rarity' => 'Promo'],
    ]);
    $elsewhere = enqueueableCard(null);

    test()->artisan('ebay:enqueue-sold', ['--set' => '30th-celebration-promos'])->assertSuccessful();

    expect(EbayScrapeJob::pluck('catalog_item_id')->all())->toBe([$unvalued->id])
        ->and(EbayScrapeJob::pluck('catalog_item_id')->all())->not->toContain($elsewhere->id);
});

test('an unknown set slug fails rather than queueing the whole catalog', function () {
    enqueueableCard(null);

    test()->artisan('ebay:enqueue-sold', ['--set' => 'no-such-set'])->assertFailed();

    expect(EbayScrapeJob::count())->toBe(0);
});

test('naming a set still skips what is already queued', function () {
    $set = Set::factory()->create(['slug' => 'promos-set', 'name' => 'Promos Set']);
    $card = CatalogItem::factory()->create([
        'set_id' => $set->id,
        'ebay_refreshed_at' => null,
        'attributes' => ['language' => 'en', 'variant' => 'normal', 'rarity' => 'Promo'],
    ]);

    test()->artisan('ebay:enqueue-sold', ['--set' => 'promos-set'])->assertSuccessful();
    test()->artisan('ebay:enqueue-sold', ['--set' => 'promos-set'])->assertSuccessful();

    expect(EbayScrapeJob::where('catalog_item_id', $card->id)->count())->toBe(1);
});

test('the queue is taken in order of how much the site looks at each card', function () {
    // The agent is slow, so the front of the queue is what actually gets done.
    // A stale price nobody opens costs nothing; the one on the page someone is
    // reading is the point.
    $ignored = enqueueableCard(null, popularity: 0);
    $popular = enqueueableCard(now()->subHours(13), popularity: 250);
    $middling = enqueueableCard(null, popularity: 40);

    test()->artisan('ebay:enqueue-sold', ['--limit' => 10])->assertSuccessful();

    expect(EbayScrapeJob::orderBy('id')->pluck('catalog_item_id')->all())
        ->toBe([$popular->id, $middling->id, $ignored->id]);
});

test('among equally popular cards the most recently viewed goes first', function () {
    $older = enqueueableCard(null, popularity: 10);
    $older->forceFill(['last_viewed_at' => now()->subMonth()])->save();

    $recent = enqueueableCard(null, popularity: 10);
    $recent->forceFill(['last_viewed_at' => now()->subMinutes(5)])->save();

    test()->artisan('ebay:enqueue-sold', ['--limit' => 10])->assertSuccessful();

    expect(EbayScrapeJob::orderBy('id')->pluck('catalog_item_id')->first())->toBe($recent->id);
});

test('a card whose rarity we do not know is still queued', function () {
    // `NOT IN` is NULL for a NULL rarity rather than true, so an unguarded
    // filter drops exactly the cards with the least data on them.
    $unknown = enqueueableCard(null, rarity: null);

    $this->artisan('ebay:enqueue-sold', ['--limit' => 10])->assertSuccessful();

    expect(EbayScrapeJob::orderBy('id')->pluck('catalog_item_id')->all())->toContain($unknown->id);
});

test('the low-value rarities the on-view refresh skips are skipped here too', function () {
    $common = enqueueableCard(null, rarity: 'Common');

    $this->artisan('ebay:enqueue-sold', ['--limit' => 10])->assertSuccessful();

    expect(EbayScrapeJob::orderBy('id')->pluck('catalog_item_id')->all())->not->toContain($common->id);
});

test('the enqueue command skips cards nobody has ever valued', function () {
    // A card with no market value has never been looked up; a scarce fetch is
    // better spent on one someone is actually pricing.
    $unvalued = CatalogItem::factory()->create([
        'ebay_refreshed_at' => null,
        'attributes' => ['language' => 'en', 'variant' => 'normal', 'rarity' => 'Rare'],
    ]);

    $this->artisan('ebay:enqueue-sold', ['--limit' => 10])->assertSuccessful();

    expect(EbayScrapeJob::orderBy('id')->pluck('catalog_item_id')->all())->not->toContain($unvalued->id);

    $this->artisan('ebay:enqueue-sold', ['--limit' => 10, '--include-unvalued' => true])->assertSuccessful();

    expect(EbayScrapeJob::orderBy('id')->pluck('catalog_item_id')->all())->toContain($unvalued->id);
});

test('the queued URL is the sold search for that card', function () {
    enqueueableCard(null);

    $this->artisan('ebay:enqueue-sold', ['--limit' => 1])->assertSuccessful();

    expect(EbayScrapeJob::first()->url)
        ->toContain('LH_Sold=1')
        ->toContain('LH_Complete=1')
        ->toContain('ebay.com/sch/i.html');
});
