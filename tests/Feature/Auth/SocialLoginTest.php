<?php

use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

/** Build a fake Socialite user and wire the facade to return it on callback. */
function fakeSocialite(string $id, ?string $email, string $name = 'FB Person'): void
{
    $oauth = Mockery::mock(SocialiteUser::class);
    $oauth->shouldReceive('getId')->andReturn($id);
    $oauth->shouldReceive('getEmail')->andReturn($email);
    $oauth->shouldReceive('getName')->andReturn($name);
    $oauth->shouldReceive('getNickname')->andReturn(null);
    $oauth->shouldReceive('getAvatar')->andReturn(null);

    $driver = Mockery::mock();
    $driver->shouldReceive('redirectUrl')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($oauth);

    Socialite::shouldReceive('driver')->with('facebook')->andReturn($driver);
}

test('brand-new facebook user is created without a username', function () {
    fakeSocialite('fb-123', 'newbie@example.com');

    $this->get(route('oauth.callback', 'facebook'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    $user = User::where('email', 'newbie@example.com')->firstOrFail();
    expect($user->username)->toBeNull();
    expect($user->provider)->toBe('facebook');
});

test('a social user without a username is gated to choose-username', function () {
    fakeSocialite('fb-123', 'newbie@example.com');
    $this->get(route('oauth.callback', 'facebook'));

    // The next page load must bounce to the username picker.
    $this->get(route('dashboard'))
        ->assertRedirect(route('username.edit', absolute: false));

    $this->get(route('username.edit'))->assertOk();
});

test('a token-exchange failure is caught and not a 500', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('redirectUrl')->andReturnSelf();
    $driver->shouldReceive('user')->andThrow(new RuntimeException('Graph API: redirect_uri mismatch'));
    Socialite::shouldReceive('driver')->with('facebook')->andReturn($driver);

    $this->get(route('oauth.callback', 'facebook'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('facebook login with no shared email is rejected', function () {
    fakeSocialite('fb-456', null);

    $this->get(route('oauth.callback', 'facebook'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('facebook login links to an existing account by email and skips the picker', function () {
    $existing = User::factory()->create([
        'email' => 'me@example.com',
        'username' => 'already_have_one',
        'provider' => null,
    ]);

    fakeSocialite('fb-789', 'me@example.com');
    $this->get(route('oauth.callback', 'facebook'));

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh()->provider)->toBe('facebook');

    // Already has a username — no gate.
    $this->get(route('dashboard'))->assertOk();
});
