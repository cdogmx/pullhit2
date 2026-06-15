<?php

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

function makeNotification(User $user, array $data = [], bool $read = false)
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\WishlistTargetHit',
        'data' => array_merge(['title' => 'Test', 'body' => 'Body', 'url' => '/wishlist'], $data),
        'read_at' => $read ? now() : null,
    ]);
}

test('the notifications page lists the user notifications', function () {
    makeNotification($this->user, ['title' => 'Hello there']);

    $this->actingAs($this->user)->get('/notifications')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/index')
            ->where('notifications.0.title', 'Hello there'));
});

test('mark all as read clears the unread count', function () {
    makeNotification($this->user);
    makeNotification($this->user);

    $this->actingAs($this->user)->post('/notifications/read-all')->assertRedirect();

    expect($this->user->unreadNotifications()->count())->toBe(0);
});

test('opening a notification marks it read and follows its url', function () {
    $n = makeNotification($this->user, ['url' => '/wishlist']);

    $this->actingAs($this->user)->get("/notifications/{$n->id}")
        ->assertRedirect('/wishlist');

    expect($n->fresh()->read_at)->not->toBeNull();
});

test('a user cannot open another user\'s notification', function () {
    $other = User::factory()->create();
    $n = makeNotification($other);

    $this->actingAs($this->user)->get("/notifications/{$n->id}")
        ->assertRedirect(route('notifications.index'));

    expect($n->fresh()->read_at)->toBeNull();
});

test('the unread count is shared to the front end', function () {
    makeNotification($this->user);

    $this->actingAs($this->user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('unreadNotifications', 1));
});

test('the notification center requires authentication', function () {
    $this->get('/notifications')->assertRedirect('/login');
});
