<?php

use App\Models\ScanLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'is_admin' => true,
        'username' => 'admin1',
        'email_verified_at' => now(),
    ]);
});

test('the user detail page renders for an admin', function () {
    $user = User::factory()->create(['username' => 'collector1', 'email_verified_at' => now()]);

    $this->actingAs($this->admin)->get("/admin/users/{$user->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/show')
            ->where('user.id', $user->id)
            ->where('links.profile', url('/u/collector1'))
            ->has('stats')
            ->has('sessions')
            ->has('scans')
            ->has('transactions'));
});

test('the detail page surfaces sessions and scan history', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    DB::table('sessions')->insert([
        'id' => 'sess-1',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    ScanLog::factory()->create([
        'user_id' => $user->id,
        'mode' => 'single',
        'cards' => 1,
        'results' => [['name' => 'Pikachu', 'number' => '58', 'match' => null]],
    ]);

    $this->actingAs($this->admin)->get("/admin/users/{$user->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sessions.0.ip_address', '203.0.113.7')
            ->where('scans.0.card_count', 1)
            ->where('stats.scans', 1));
});

test('users with no username get null links', function () {
    $user = User::factory()->create(['username' => null, 'email_verified_at' => now()]);

    $this->actingAs($this->admin)->get("/admin/users/{$user->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('links', null));
});

test('non-admins cannot view the detail page', function () {
    $user = User::factory()->create(['username' => 'plain1', 'email_verified_at' => now()]);

    $this->actingAs($user)->get("/admin/users/{$user->id}")->assertForbidden();
});
