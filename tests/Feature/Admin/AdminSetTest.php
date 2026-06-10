<?php

use App\Jobs\ImportSetJob;
use App\Models\CatalogItem;
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
