<?php

use App\Actions\RipOrKeep\BuildSealedDossier;
use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.anthropic.key' => 'test-key']);

    $this->set = Set::factory()->create([
        'name' => 'Chaos Rising', 'released_at' => now()->subYears(2),
    ]);

    $this->box = CatalogItem::factory()->sealed()->create([
        'name' => 'Chaos Rising Booster Box',
        'set_id' => $this->set->id,
        'product_line_id' => $this->set->product_line_id,
    ]);
    MarketValue::factory()->for($this->box)->create([
        'state_key' => 'SEALED', 'condition' => Condition::Sealed,
        'grading_company_id' => null, 'median' => 60000,
    ]);

    // A chase single in the same set (the "rip upside").
    $chase = CatalogItem::factory()->create([
        'name' => 'Charizard', 'set_id' => $this->set->id,
        'product_line_id' => $this->set->product_line_id,
    ]);
    MarketValue::factory()->for($chase)->create([
        'state_key' => 'NM', 'condition' => Condition::NearMint,
        'grading_company_id' => null, 'median' => 30000,
    ]);
});

/** A faked Sensei text response. */
function fakeSensei(string $text = "🥋 KEEP THE WAX (72%)\nSealed is climbing while the chase cooled."): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]],
        ], 200),
    ]);
}

test('the dossier assembles the sealed value, set, and chase singles', function () {
    $d = app(BuildSealedDossier::class)($this->box);

    expect($d['sealed_value'])->toBe(60000)
        ->and($d['product']['name'])->toBe('Chaos Rising Booster Box')
        ->and($d['set']['name'])->toBe('Chaos Rising')
        ->and($d['set']['age_years'])->toBeGreaterThan(1.5)
        ->and($d['chase']['top'][0]['name'])->toContain('Charizard')
        ->and($d['chase']['top'][0]['value'])->toBe(30000)
        ->and($d['chase']['single_count'])->toBe(1);
});

test('the rip-or-keep page renders for guests', function () {
    $this->get('/rip-or-keep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rip-or-keep/index'));
});

test('search returns only sealed products', function () {
    $this->getJson('/rip-or-keep/search?q=chaos')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.id', $this->box->id);
});

test('the Sensei answers with a verdict for a sealed product', function () {
    fakeSensei();

    $this->postJson("/rip-or-keep/{$this->box->id}/chat", [
        'messages' => [['role' => 'user', 'content' => 'Rip or keep?']],
    ])
        ->assertOk()
        ->assertJsonPath('reply', fn ($r) => str_contains($r, 'KEEP THE WAX'));

    // The prompt must carry the real dossier data (sealed value in dollars).
    Http::assertSent(fn ($request) => str_contains($request->data()['system'] ?? '', '$600.00'));
});

test('chat is rejected for a non-sealed item', function () {
    $single = CatalogItem::factory()->create();

    $this->postJson("/rip-or-keep/{$single->id}/chat", [
        'messages' => [['role' => 'user', 'content' => 'Rip or keep?']],
    ])->assertNotFound();
});

test('chat validates the message payload', function () {
    $this->postJson("/rip-or-keep/{$this->box->id}/chat", ['messages' => []])
        ->assertStatus(422);
});
