<?php

use App\Enums\MembershipTier;
use App\Exceptions\TooManyScansException;
use App\Models\User;
use App\Support\Membership\ScanQuota;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

test('caps come from the tier config', function () {
    config(['membership.scan_caps.free' => 75, 'membership.scan_caps.collector' => 2000]);

    $free = User::factory()->create();
    $collector = User::factory()->create(['membership_tier' => MembershipTier::Collector]);

    expect(ScanQuota::for($free)->cap())->toBe(75)
        ->and(ScanQuota::for($collector)->cap())->toBe(2000);
});

test('record increments the current month and ensure throws past the cap', function () {
    config(['membership.scan_caps.free' => 75]);
    $user = User::factory()->create();
    $quota = ScanQuota::for($user);

    $quota->record(74);
    expect($quota->used())->toBe(74)
        ->and($quota->remaining())->toBe(1);
    $quota->ensure(); // still has 1 left — fine

    $quota->record(1);
    expect($quota->remaining())->toBe(0);
    expect(fn () => $quota->ensure())->toThrow(TooManyScansException::class);
});

test('purchased credits cover overflow past the monthly allowance', function () {
    config(['membership.scan_caps.free' => 5]);
    $user = User::factory()->create();
    $user->forceFill(['purchased_scan_credits' => 10])->save();
    $quota = ScanQuota::for($user);

    expect($quota->remaining())->toBe(15); // 5 monthly + 10 credits

    // Spend 7: 5 from the monthly allowance, then 2 from purchased credits.
    $spent = $quota->record(7);

    expect($spent)->toBe(2)
        ->and($quota->used())->toBe(5)
        ->and($quota->monthlyRemaining())->toBe(0)
        ->and($user->fresh()->purchased_scan_credits)->toBe(8)
        ->and($quota->remaining())->toBe(8);

    $quota->ensure(); // still has credits — no throw
});

test('usage is tracked per month and resets next period', function () {
    config(['membership.scan_caps.free' => 75]);
    $user = User::factory()->create();

    Carbon::setTestNow('2026-06-15');
    ScanQuota::for($user)->record(40);
    expect(ScanQuota::for($user)->used())->toBe(40);

    Carbon::setTestNow('2026-07-01');
    expect(ScanQuota::for($user)->used())->toBe(0)
        ->and(ScanQuota::for($user)->remaining())->toBe(75);
});
