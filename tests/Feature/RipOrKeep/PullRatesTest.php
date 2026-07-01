<?php

use App\Actions\RipOrKeep\BuildSealedDossier;
use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\SetPullOdd;
use App\Support\RipOrKeep\PullRateResearcher;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.anthropic.key' => 'test-key']));

/** Fake the researcher's structured tool_use response. */
function fakeRates(array $rates): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'tool_use', 'name' => 'record_pull_rates',
                'input' => ['rates' => $rates],
            ]],
        ], 200),
    ]);
}

test('the researcher keeps only in-vocabulary, sane rates', function () {
    fakeRates([
        ['rarity' => 'Special Illustration Rare', 'per_pack_prob' => 0.08, 'source' => 'https://ex.test/a', 'confidence' => 0.7],
        ['rarity' => 'Made Up Rarity', 'per_pack_prob' => 0.5],   // out of vocab → dropped
        ['rarity' => 'Ultra Rare', 'per_pack_prob' => 2.0],       // impossible prob → dropped
    ]);

    $out = app(PullRateResearcher::class)->research('Surging Sparks', [
        'Special Illustration Rare', 'Ultra Rare',
    ]);

    expect($out)->toHaveCount(1)
        ->and($out[0]['rarity'])->toBe('Special Illustration Rare')
        ->and($out[0]['per_pack_prob'])->toBe(0.08)
        ->and($out[0]['source'])->toBe('https://ex.test/a');
});

test('the command researches an SV set and stores odds', function () {
    $pokemon = ProductLine::factory()->create(['slug' => 'pokemon']);
    $set = Set::factory()->for($pokemon)->create([
        'slug' => 'surging-sparks', 'name' => 'Surging Sparks', 'series' => 'Scarlet & Violet',
    ]);
    CatalogItem::factory()->create([
        'set_id' => $set->id, 'product_line_id' => $pokemon->id,
        'attributes' => ['language' => 'en', 'rarity' => 'Special Illustration Rare', 'variant' => 'holo'],
    ]);

    fakeRates([
        ['rarity' => 'Special Illustration Rare', 'per_pack_prob' => 0.08, 'source' => 'https://ex.test/a', 'confidence' => 0.7],
    ]);

    $this->artisan('pull-rates:search --set=surging-sparks')->assertSuccessful();

    $this->assertDatabaseHas('set_pull_odds', [
        'set_id' => $set->id, 'rarity' => 'Special Illustration Rare', 'method' => 'ai_search',
    ]);
});

test('the dossier models rip EV from stored odds x rarity values', function () {
    $set = Set::factory()->create();

    // A booster box (36 packs by default) in the set.
    $box = CatalogItem::factory()->sealed()->create([
        'name' => 'Surging Sparks Booster Box',
        'set_id' => $set->id, 'product_line_id' => $set->product_line_id,
    ]);
    MarketValue::factory()->for($box)->create([
        'state_key' => 'SEALED', 'condition' => Condition::Sealed,
        'grading_company_id' => null, 'median' => 60000,
    ]);

    // One SIR single worth $500, and a researched 8%/pack SIR rate.
    $sir = CatalogItem::factory()->create([
        'set_id' => $set->id, 'product_line_id' => $set->product_line_id,
        'attributes' => ['language' => 'en', 'rarity' => 'Special Illustration Rare', 'variant' => 'holo'],
    ]);
    MarketValue::factory()->for($sir)->create([
        'state_key' => 'NM', 'condition' => Condition::NearMint,
        'grading_company_id' => null, 'median' => 50000,
    ]);
    SetPullOdd::create([
        'set_id' => $set->id, 'rarity' => 'Special Illustration Rare',
        'per_pack_prob' => 0.08, 'method' => 'ai_search', 'confidence' => 0.7,
    ]);

    $ev = app(BuildSealedDossier::class)($box)['rip_ev'];

    // 0.08 x $500 = $40/pack; x 36 packs = $1,440.
    expect($ev)->not->toBeNull()
        ->and($ev['ev_per_pack'])->toBe(4000)
        ->and($ev['packs'])->toBe(36)
        ->and($ev['ev_total'])->toBe(144000);
});

test('the dossier has null rip EV when a set has no odds', function () {
    $set = Set::factory()->create();
    $box = CatalogItem::factory()->sealed()->create([
        'set_id' => $set->id, 'product_line_id' => $set->product_line_id,
    ]);
    MarketValue::factory()->for($box)->create([
        'state_key' => 'SEALED', 'condition' => Condition::Sealed,
        'grading_company_id' => null, 'median' => 60000,
    ]);

    expect(app(BuildSealedDossier::class)($box)['rip_ev'])->toBeNull();
});
