<?php

use App\Actions\Grading\BuildGradingDossier;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.anthropic.key' => 'test-key']);
    config(['grading.fee' => 25, 'grading.shipping' => 10, 'grading.sale_fee_pct' => 0.0]);

    $this->psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    $this->card = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4']);
    MarketValue::factory()->for($this->card)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null,
        'median' => 5000, 'n_sales' => 30, 'is_estimated' => false,
    ]);
    MarketValue::factory()->for($this->card)->create([
        'state_key' => 'psa-10', 'grading_company_id' => $this->psa->id, 'grade' => 10,
        'median' => 40000, 'n_sales' => 8, 'is_estimated' => false,
    ]);
});

function fakeGradeSensei(string $text = "🥋 GRADE IT (68%)\nThe PSA 10 premium dwarfs the fee."): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]],
        ], 200),
    ]);
}

test('the dossier assembles raw + graded values and the EV advice', function () {
    $d = app(BuildGradingDossier::class)($this->card);

    expect($d['kind'])->toBe('grade')
        ->and($d['raw']['value'])->toBe(5000)
        ->and($d['graded']['10']['value'])->toBe(40000)
        ->and($d['graded']['10']['estimated'])->toBeFalse()
        // PSA 9 has no real comp -> modeled from the 10, flagged estimated.
        ->and($d['graded']['9']['estimated'])->toBeTrue()
        ->and($d['graded']['9']['value'])->toBeGreaterThan(0)
        ->and($d['advice'])->not->toBeNull()
        ->and($d['advice']['verdict'])->toBe('grade')
        // Break-even = (fee+ship)/(psa10 - raw) = 3500 / (40000-5000) = 0.1.
        ->and($d['advice']['breakeven_p10'])->toBe(0.1);
});

test('advice is null when there is no PSA 10 comp to anchor on', function () {
    $bare = CatalogItem::factory()->create();
    MarketValue::factory()->for($bare)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 5000, 'is_estimated' => false,
    ]);

    expect(app(BuildGradingDossier::class)($bare)['advice'])->toBeNull();
});

test('the dossier endpoint returns JSON for a single', function () {
    $this->getJson("/grade/{$this->card->id}/dossier")
        ->assertOk()
        ->assertJsonPath('advice.verdict', 'grade')
        ->assertJsonPath('graded.10.value', 40000);
});

test('the Sensei answers with a grade-or-sell verdict', function () {
    fakeGradeSensei();

    $this->postJson("/grade/{$this->card->id}/chat", [
        'messages' => [['role' => 'user', 'content' => 'Grade or sell?']],
    ])
        ->assertOk()
        ->assertJsonPath('reply', fn ($r) => str_contains($r, 'GRADE IT'));

    // The prompt carries the real dossier: raw $50, PSA 10 $400, and the break-even.
    Http::assertSent(fn ($request) => str_contains($request->data()['system'] ?? '', '$400.00')
        && str_contains($request->data()['system'] ?? '', 'BREAK-EVEN'));
});

test('grade chat is rejected for a sealed product', function () {
    $sealed = CatalogItem::factory()->sealed()->create();

    $this->postJson("/grade/{$sealed->id}/chat", [
        'messages' => [['role' => 'user', 'content' => 'Grade or sell?']],
    ])->assertNotFound();
});

test('grade chat validates the message payload', function () {
    $this->postJson("/grade/{$this->card->id}/chat", ['messages' => []])
        ->assertStatus(422);
});
