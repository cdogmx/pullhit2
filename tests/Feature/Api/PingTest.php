<?php

use App\Models\User;

test('public api ping returns ok without auth', function () {
    $response = $this->getJson('/api/v1/ping');

    $response
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'api',
            'version' => 'v1',
        ]);
});

test('protected api route rejects requests without a token', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

test('protected api route accepts a valid sanctum token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);
});
