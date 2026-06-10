<?php

use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

$urls = ['/admin', '/admin/sets', '/admin/cards'];

test('guests are redirected to login', function () {
    $this->get('/admin')->assertRedirect('/login');
});

test('non-admins are forbidden from the admin area', function () use ($urls) {
    foreach ($urls as $url) {
        $this->actingAs($this->user)->get($url)->assertForbidden();
    }
});

test('admins can reach the admin area', function () use ($urls) {
    foreach ($urls as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }
});
