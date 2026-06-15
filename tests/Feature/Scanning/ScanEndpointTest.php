<?php

use App\Models\CatalogItem;
use App\Models\ScanFingerprint;
use App\Models\User;
use App\Support\Membership\ScanQuota;
use Illuminate\Support\Facades\Http;

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
