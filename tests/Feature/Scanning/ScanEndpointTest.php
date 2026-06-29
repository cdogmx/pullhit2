<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\ScanFingerprint;
use App\Models\ScanLog;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use App\Support\Membership\ScanQuota;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the scan endpoint requires authentication', function () {
    $this->post('/scan', ['image' => tinyJpeg(), 'media_type' => 'image/jpeg', 'mode' => 'single'])
        ->assertRedirect('/login');
});

test('confirming a scan teaches the recognition cache', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $item = CatalogItem::factory()->create();

    $this->actingAs($user)->postJson('/scan/confirm', [
        'fingerprint' => 'abcdef0123456789',
        'catalog_item_id' => $item->id,
    ])->assertOk()->assertJsonPath('ok', true);

    expect(ScanFingerprint::where('catalog_item_id', $item->id)
        ->where('phash', 'abcdef0123456789')->exists())->toBeTrue();
});

test('scan confirm rejects a malformed fingerprint', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $item = CatalogItem::factory()->create();

    $this->actingAs($user)->from('/scan')->post('/scan/confirm', [
        'fingerprint' => 'NOT-HEX', 'catalog_item_id' => $item->id,
    ])->assertRedirect('/scan')->assertSessionHasErrors('fingerprint');

    expect(ScanFingerprint::count())->toBe(0);
});

test('scan confirm requires authentication', function () {
    $this->post('/scan/confirm', ['fingerprint' => 'abcdef0123456789', 'catalog_item_id' => 1])
        ->assertRedirect('/login');
});

test('scan search returns matching catalog items', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'username' => 'scanner1']);
    $item = CatalogItem::factory()->create(['name' => 'Charizard', 'number' => '4/102']);

    $this->actingAs($user)->getJson('/scan/search?q=Charizard')
        ->assertOk()
        ->assertJsonPath('results.0.id', $item->id);
});

test('scan search matches by set name', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'username' => 'scanner2']);

    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon']);
    $set = Set::factory()->for($line)->create(['name' => 'Perfect Order', 'slug' => 'perfect-order']);
    $item = CatalogItem::factory()->for($vertical)->for($line)->for($set)
        ->create(['name' => 'Energy Recycler', 'number' => '108']);

    $this->actingAs($user)->getJson('/scan/search?q=perfect order')
        ->assertOk()
        ->assertJsonPath('results.0.id', $item->id);
});

test('scan search ignores too-short queries', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'username' => 'scanner3']);

    $this->actingAs($user)->getJson('/scan/search?q=a')
        ->assertOk()
        ->assertExactJson(['results' => []]);
});

test('scan search requires authentication', function () {
    $this->get('/scan/search?q=charizard')->assertRedirect('/login');
});

test('the scan page exposes the user\'s collection folders', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $item = CatalogItem::factory()->create();
    app(\App\Actions\Collection\AddToCollection::class)($user, $item, [
        'condition' => 'NM', 'quantity' => 1, 'unit_cost' => 0, 'folder' => 'Slabs',
    ]);

    $this->actingAs($user)->get('/scan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('folders', ['Slabs']));
});

test('a user can remove their own scan from history', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $log = ScanLog::factory()->for($user)->create(['image_path' => 'https://example.test/s.jpg']);

    $this->actingAs($user)->delete("/scan/history/{$log->id}")->assertRedirect();
    $this->assertDatabaseMissing('scan_logs', ['id' => $log->id]);
});

test('a user cannot remove another user\'s scan', function () {
    $log = ScanLog::factory()->create(['image_path' => 'https://example.test/s.jpg']);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->delete("/scan/history/{$log->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('scan_logs', ['id' => $log->id]);
});

test('scan history totals each scan by the matched cards current value', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'username' => 'historian']);

    $a = CatalogItem::factory()->create(['name' => 'Pikachu', 'number' => '58']);
    $b = CatalogItem::factory()->create(['name' => 'Raichu', 'number' => '59']);
    MarketValue::factory()->for($a)->create(['state_key' => 'NM', 'grading_company_id' => null, 'median' => 1500]);
    MarketValue::factory()->for($b)->create(['state_key' => 'NM', 'grading_company_id' => null, 'median' => 800]);

    ScanLog::factory()->for($user)->create([
        'mode' => 'bulk',
        'image_path' => 'https://example.test/scan.jpg',
        'cards' => 2,
        'results' => [
            ['name' => 'Pikachu', 'number' => '58', 'source' => 'vision', 'match' => ['id' => $a->id, 'name' => 'Pikachu', 'number' => '58', 'set' => null, 'image_url' => null, 'url' => null]],
            ['name' => 'Raichu', 'number' => '59', 'source' => 'cache', 'match' => ['id' => $b->id, 'name' => 'Raichu', 'number' => '59', 'set' => null, 'image_url' => null, 'url' => null]],
        ],
    ]);

    $this->actingAs($user)->get('/scan/history')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('scan/history')
            ->where('scans.0.total_value', 2300)
            ->where('scans.0.priced_count', 2)
            ->where('scans.0.results.0.value', 1500)
            ->where('scans.0.results.1.value', 800));
});

test('scan history shows null value for an unpriced matched card', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'username' => 'historian2']);
    $item = CatalogItem::factory()->create(['name' => 'Mew', 'number' => '151']); // no market value

    ScanLog::factory()->for($user)->create([
        'image_path' => 'https://example.test/scan.jpg',
        'cards' => 1,
        'results' => [
            ['name' => 'Mew', 'number' => '151', 'source' => 'vision', 'match' => ['id' => $item->id, 'name' => 'Mew', 'number' => '151', 'set' => null, 'image_url' => null, 'url' => null]],
        ],
    ]);

    $this->actingAs($user)->get('/scan/history')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scans.0.total_value', 0)
            ->where('scans.0.priced_count', 0)
            ->where('scans.0.results.0.value', null));
});

test('a free user past the monthly cap is blocked with 429', function () {
    Http::fake(fakeAnthropic());
    config(['membership.scan_caps.free' => 1]);

    $user = User::factory()->create(['email_verified_at' => now()]);
    ScanQuota::for($user)->record(1); // at the cap

    $this->actingAs($user)
        ->postJson('/scan', ['image' => tinyJpeg(), 'media_type' => 'image/jpeg', 'mode' => 'single'])
        ->assertStatus(429)
        ->assertJsonPath('usage.remaining', 0);
});

test('an admin is never blocked', function () {
    Http::fake(fakeAnthropic());
    config(['membership.admins' => ['clint.r.chaney@gmail.com'], 'membership.scan_caps.free' => 1]);

    $admin = User::factory()->create(['email' => 'clint.r.chaney@gmail.com', 'email_verified_at' => now()]);
    ScanQuota::for($admin)->record(9999);

    $this->actingAs($admin)
        ->postJson('/scan', ['image' => tinyJpeg(), 'media_type' => 'image/jpeg', 'mode' => 'single'])
        ->assertOk()
        ->assertJsonStructure(['detected', 'usage']);
});
