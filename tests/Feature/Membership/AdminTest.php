<?php

use App\Models\User;
use App\Support\Membership\Entitlements;
use App\Support\Membership\ScanQuota;

test('a configured admin email is promoted automatically on sign-up', function () {
    config(['membership.admins' => ['clint.r.chaney@gmail.com']]);

    $admin = User::factory()->create(['email' => 'clint.r.chaney@gmail.com']);
    $normal = User::factory()->create(['email' => 'someone@example.com']);

    expect($admin->isAdmin())->toBeTrue()
        ->and($normal->isAdmin())->toBeFalse();
});

test('users:make-admin promotes an existing user', function () {
    $user = User::factory()->create(['email' => 'late@example.com']);
    expect($user->isAdmin())->toBeFalse();

    $this->artisan('users:make-admin', ['email' => 'late@example.com'])->assertSuccessful();

    expect($user->fresh()->isAdmin())->toBeTrue();
});

test('users:make-admin fails for an unknown email', function () {
    $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
});

test('admins have unlimited access and scans', function () {
    $admin = User::factory()->create(['email' => 'clint.r.chaney@gmail.com']);

    $ent = Entitlements::for($admin);
    expect($ent->isAdmin())->toBeTrue()
        ->and($ent->isPremium())->toBeTrue()
        ->and($ent->can('anything_at_all'))->toBeTrue()
        ->and($ent->scanCap())->toBe(PHP_INT_MAX);

    $quota = ScanQuota::for($admin);
    $quota->record(10_000);
    $quota->ensure(); // never throws for admins
    expect($quota->snapshot()['unlimited'])->toBeTrue();
});
