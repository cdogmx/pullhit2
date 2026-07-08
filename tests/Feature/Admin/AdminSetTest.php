<?php

use App\Jobs\ImportSetJob;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\SeriesMeta;
use App\Models\Set;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
});

test('import queues a set import job', function () {
    Queue::fake();

    $this->actingAs($this->admin)->post('/admin/sets/import', ['set_id' => 'sv8'])->assertRedirect();

    Queue::assertPushed(ImportSetJob::class, fn (ImportSetJob $j) => $j->setId === 'sv8');
});

test('resync queues an import for the existing set', function () {
    Queue::fake();
    $set = Set::factory()->create(['external_ids' => ['ptcgio_id' => 'sv8']]);

    $this->actingAs($this->admin)->post("/admin/sets/{$set->id}/resync")->assertRedirect();

    Queue::assertPushed(ImportSetJob::class, fn (ImportSetJob $j) => $j->setId === 'sv8');
});

test('the missing report lists cards absent from the catalog', function () {
    Http::fake([
        'api.pokemontcg.io/v2/cards*' => Http::response([
            'data' => [
                ['id' => 'sv8-1', 'number' => '1', 'name' => 'Alpha'],
                ['id' => 'sv8-2', 'number' => '2', 'name' => 'Beta'],
            ],
            'count' => 2, 'pageSize' => 250,
        ]),
    ]);

    $set = Set::factory()->create(['external_ids' => ['ptcgio_id' => 'sv8']]);
    CatalogItem::factory()->for($set)->create(['external_ids' => ['ptcgio_id' => 'sv8-1']]);

    $this->actingAs($this->admin)->getJson("/admin/sets/{$set->id}/missing")
        ->assertOk()
        ->assertJsonPath('expected', 2)
        ->assertJsonPath('present', 1)
        ->assertJsonPath('missing.0.id', 'sv8-2');
});

test('updating a set edits series (and leaves brand/language alone)', function () {
    $set = Set::factory()->create(['name' => 'Old', 'series' => null, 'language' => 'ja']);

    $this->actingAs($this->admin)
        ->patch("/admin/sets/{$set->id}", [
            'name' => 'First Partner Illustration Collection - Series 2',
            'series' => 'First Partners',
        ])
        ->assertRedirect();

    $set->refresh();
    expect($set->series)->toBe('First Partners')
        ->and($set->name)->toBe('First Partner Illustration Collection - Series 2')
        ->and($set->language)->toBe('ja'); // untouched
});

test('rename-series moves every set in a brand series (and can ungroup)', function () {
    $line = ProductLine::factory()->create();
    $a = Set::factory()->for($line)->create(['series' => 'Old Series']);
    $b = Set::factory()->for($line)->create(['series' => 'Old Series']);
    $other = Set::factory()->for($line)->create(['series' => 'Keep']);

    // Rename + set an image.
    $this->actingAs($this->admin)
        ->post('/admin/structure/series', [
            'product_line_id' => $line->id,
            'from' => 'Old Series',
            'to' => 'New Series',
            'logo_url' => 'https://cdn.example.com/new-series.png',
        ])->assertRedirect();

    expect($a->fresh()->series)->toBe('New Series')
        ->and($b->fresh()->series)->toBe('New Series')
        ->and($other->fresh()->series)->toBe('Keep'); // untouched

    // The image landed on the (renamed) series.
    expect(SeriesMeta::where('product_line_id', $line->id)->where('name', 'New Series')->value('logo_path'))
        ->toBe('https://cdn.example.com/new-series.png');

    // Ungroup (blank target) drops the metadata too.
    $this->actingAs($this->admin)
        ->post('/admin/structure/series', [
            'product_line_id' => $line->id,
            'from' => 'New Series',
            'to' => '',
        ])->assertRedirect();

    expect($a->fresh()->series)->toBeNull()
        ->and($b->fresh()->series)->toBeNull()
        ->and(SeriesMeta::where('product_line_id', $line->id)->where('name', 'New Series')->exists())->toBeFalse();
});
