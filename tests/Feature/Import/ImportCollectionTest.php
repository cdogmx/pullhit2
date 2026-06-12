<?php

use App\Models\CatalogItem;
use App\Models\Set;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->set = Set::factory()->create(['language' => 'en', 'name' => 'Surging Sparks']);
    $this->item = CatalogItem::factory()->for($this->set)->create([
        'name' => 'Pikachu', 'number' => '25',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
});

test('the import page requires authentication', function () {
    $this->get('/collection/import')->assertRedirect('/login');
});

test('preview parses and matches an uploaded PriceCharting CSV', function () {
    $csv = "id,product-name,console-name,include-string,quantity,cost-basis-in-pennies,folder\n"
        ."1,Pikachu #25,Pokemon Surging Sparks,Ungraded,2,500,Binder A\n"
        ."2,Charizard #199,Pokemon Ascended Heroes,Ungraded,1,0,\n"; // set we don't have

    $file = UploadedFile::fake()->createWithContent('export.csv', $csv);

    $this->actingAs($this->user)
        ->post('/collection/import/preview', ['file' => $file])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collection/import')
            ->where('counts.parsed', 2)
            ->where('counts.matched', 1)
            ->where('counts.unmatched', 1)
            ->where('importable.0.name', 'Pikachu')
            ->where('importable.0.quantity', 2)
            ->where('importable.0.unit_cost', 250) // 500 total / 2
            ->where('importable.0.folder', 'Binder A'),
        );
});

test('commit creates holdings with folder and cost basis', function () {
    $rows = [[
        'catalog_item_id' => $this->item->id,
        'condition' => 'NM',
        'grading_company_id' => null,
        'grade' => null,
        'quantity' => 2,
        'unit_cost' => 250,
        'acquired_at' => null,
        'folder' => 'Binder A',
        'notes' => null,
    ]];

    $this->actingAs($this->user)
        ->post('/collection/import', ['rows' => $rows])
        ->assertRedirect('/collection');

    $holding = $this->user->collectionItems()->first();
    expect($this->user->collectionItems()->count())->toBe(1)
        ->and($holding->folder)->toBe('Binder A')
        ->and($holding->quantity)->toBe(2)
        ->and($holding->costBasisCents())->toBe(500); // 2 × 250
});
