<?php

use App\Models\User;
use App\Notifications\WishlistTargetHit;
use Illuminate\Support\Collection;

test('the notifications settings page lists the toggleable types', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get('/settings/notifications')
        ->assertOk();
});

test('a user can update their notification preferences', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->patch('/settings/notifications', [
        'preferences' => ['wishlist_targets' => false, 'edit_reviews' => true],
    ])->assertRedirect();

    expect($user->fresh()->notification_preferences)
        ->toBe(['wishlist_targets' => false, 'edit_reviews' => true]);
});

test('an omitted type is stored as off (explicit opt-out)', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->patch('/settings/notifications', [
        'preferences' => ['edit_reviews' => true],
    ])->assertRedirect();

    expect($user->fresh()->notification_preferences['wishlist_targets'])->toBeFalse();
});

test('wantsNotification defaults to on when unset', function () {
    $user = User::factory()->create();

    expect($user->wantsNotification('wishlist_targets'))->toBeTrue()
        ->and($user->wantsNotification('edit_reviews'))->toBeTrue();
});

test('opting out removes the mail channel for that notification', function () {
    $user = User::factory()->create();
    $user->notification_preferences = ['wishlist_targets' => false, 'edit_reviews' => true];
    $user->save();

    $notification = new WishlistTargetHit(new Collection);

    expect($notification->via($user))->toBe([]); // suppressed

    // A user who hasn't opted out still gets it.
    $optedIn = User::factory()->create();
    expect($notification->via($optedIn))->toBe(['mail']);
});
