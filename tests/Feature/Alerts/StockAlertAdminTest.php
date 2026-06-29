<?php

use App\Models\TrackedProduct;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'is_admin' => true,
        'username' => 'admin1',
        'email_verified_at' => now(),
    ]);
});

it('creates a product with a name, target and image', function () {
    $this->actingAs($this->admin)->post('/admin/stock-alerts', [
        'name' => 'Surging Sparks ETB',
        'image_url' => 'https://cdn.test/box.jpg',
        'target_price' => 49.99,
        'currency' => 'USD',
        'check_interval_minutes' => 15,
    ])->assertRedirect();

    $p = TrackedProduct::firstOrFail();
    expect($p->name)->toBe('Surging Sparks ETB')
        ->and($p->image_url)->toBe('https://cdn.test/box.jpg')
        ->and($p->target_price)->toBe(4999);
});

it('edits a product name, image and target', function () {
    $p = TrackedProduct::create([
        'name' => 'Old name',
        'target_price' => 2000,
        'currency' => 'USD',
    ]);

    $this->actingAs($this->admin)->patch("/admin/stock-alerts/{$p->id}", [
        'name' => 'New name',
        'image_url' => 'https://cdn.test/new.jpg',
        'target_price' => 59.99,
        'currency' => 'USD',
        'check_interval_minutes' => 30,
    ])->assertRedirect();

    $p->refresh();
    expect($p->name)->toBe('New name')
        ->and($p->image_url)->toBe('https://cdn.test/new.jpg')
        ->and($p->target_price)->toBe(5999)
        ->and($p->check_interval_minutes)->toBe(30);
});

it('rejects a product with neither name nor catalog item', function () {
    $this->actingAs($this->admin)->post('/admin/stock-alerts', [
        'target_price' => 9.99,
        'currency' => 'USD',
        'check_interval_minutes' => 15,
    ])->assertSessionHasErrors('name');

    expect(TrackedProduct::count())->toBe(0);
});
