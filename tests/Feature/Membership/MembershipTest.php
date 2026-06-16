<?php

use App\Enums\MembershipTier;
use App\Models\User;
use App\Support\Membership\Entitlements;

test('new users default to the free tier', function () {
    $user = User::factory()->create();

    expect($user->fresh()->membership_tier)->toBe(MembershipTier::Free)
        ->and($user->isPremium())->toBeFalse();
});

test('entitlements expose the configured scan cap per tier', function () {
    config(['membership.scan_caps.free' => 75, 'membership.scan_caps.collector' => 2000]);

    $free = User::factory()->create();
    $premium = User::factory()->create(['membership_tier' => MembershipTier::Collector]);

    expect(Entitlements::for($free)->scanCap())->toBe(75)
        ->and(Entitlements::for($premium)->scanCap())->toBe(2000)
        ->and(Entitlements::for($premium)->isPremium())->toBeTrue();
});

test('collection and portfolio are entitled on every tier; other features are premium-only', function () {
    $free = User::factory()->create();
    $premium = User::factory()->create(['membership_tier' => MembershipTier::Collector]);

    expect(Entitlements::for($free)->can('collection'))->toBeTrue()
        ->and(Entitlements::for($free)->can('portfolio'))->toBeTrue()
        ->and(Entitlements::for($free)->can('something_premium'))->toBeFalse()
        ->and(Entitlements::for($premium)->can('something_premium'))->toBeTrue();
});
