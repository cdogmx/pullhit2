<?php

use App\Models\User;
use App\Support\Membership\ScanQuota;
use Illuminate\Support\Facades\Http;

test('the scan endpoint requires authentication', function () {
    $this->post('/scan', ['image' => tinyJpeg(), 'media_type' => 'image/jpeg', 'mode' => 'single'])
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
