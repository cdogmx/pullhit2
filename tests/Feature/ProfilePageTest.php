<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('the public profile page renders for a username', function () {
    $user = User::factory()->create([
        'username' => 'collector',
        'contribution_points' => 100,
        'email_verified_at' => now(),
    ]);

    $this->get('/u/collector')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/show')
            ->where('profile.username', 'collector')
            ->where('profile.points', 100)
            ->has('profile.level')
            ->has('recent')
            ->where('meta.og_type', 'profile')
            ->where('meta.title', '@collector on CardFoo'));
});

test('an unknown profile username 404s', function () {
    $this->get('/u/nobody')->assertNotFound();
});

test('a user can upload and remove an avatar', function () {
    Storage::fake('s3');
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->post('/settings/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 200, 200)])
        ->assertRedirect();

    expect($user->fresh()->avatar_path)->not->toBeNull();

    $this->actingAs($user)->delete('/settings/avatar')->assertRedirect();

    expect($user->fresh()->avatar_path)->toBeNull();
});

test('the avatar is exposed as `avatar` on the user', function () {
    $user = User::factory()->create(['avatar_path' => 'https://cdn.example/me.png']);

    expect($user->avatar)->toBe('https://cdn.example/me.png')
        ->and($user->toArray())->toHaveKey('avatar');
});
