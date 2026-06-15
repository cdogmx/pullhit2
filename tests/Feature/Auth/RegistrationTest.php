<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => 'test_user',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
    expect(User::whereUsername('test_user')->exists())->toBeTrue();
});

test('registration requires a username', function () {
    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
    $this->assertGuest();
});

test('registration rejects a reserved or duplicate username', function () {
    User::factory()->create(['username' => 'taken_name']);

    $reserved = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'A', 'username' => 'admin', 'email' => 'a@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ]);
    $reserved->assertSessionHasErrors('username');

    $dupe = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'B', 'username' => 'taken_name', 'email' => 'b@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ]);
    $dupe->assertSessionHasErrors('username');

    $badChars = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'C', 'username' => 'no spaces!', 'email' => 'c@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ]);
    $badChars->assertSessionHasErrors('username');

    $this->assertGuest();
});