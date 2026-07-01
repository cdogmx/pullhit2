<?php

use App\Actions\Collection\AddToCollection;
use App\Actions\Community\RecordDailyCheckin;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Scanning\RecordScanFeedback;
use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Inertia\Testing\AssertableInertia as Assert;

test('scan feedback earns points once per fingerprint', function () {
    $user = User::factory()->create();
    $record = app(RecordScanFeedback::class);

    $record($user, ['source' => 'vision', 'was_correct' => true, 'phash' => 'abc123']);
    expect($user->fresh()->contribution_points)->toBe(2);

    // Same fingerprint again — no double award.
    $record($user, ['source' => 'vision', 'was_correct' => true, 'phash' => 'abc123']);
    expect($user->fresh()->contribution_points)->toBe(2);

    // A different card earns again.
    $record($user, ['source' => 'vision', 'was_correct' => true, 'phash' => 'def456']);
    expect($user->fresh()->contribution_points)->toBe(4);
});

test('adding the first collection card earns the milestone once', function () {
    $user = User::factory()->create();
    $item = CatalogItem::factory()->create();
    $add = app(AddToCollection::class);

    $add($user, $item, ['condition' => 'NM', 'quantity' => 1, 'unit_cost' => 0]);
    expect($user->fresh()->contribution_points)->toBe(5);

    $add($user, $item, ['condition' => 'LP', 'quantity' => 1, 'unit_cost' => 0]);
    expect($user->fresh()->contribution_points)->toBe(5); // still once
});

test('daily check-in awards once per day and a 7-day streak bonus', function () {
    $user = User::factory()->create();
    $checkin = app(RecordDailyCheckin::class);

    $checkin($user->fresh());
    expect($user->fresh()->contribution_points)->toBe(2)
        ->and($user->fresh()->checkin_streak)->toBe(1);

    // Same day: no-op.
    $checkin($user->fresh());
    expect($user->fresh()->contribution_points)->toBe(2);

    // Six more consecutive days → 7-day streak triggers the bonus.
    for ($day = 2; $day <= 7; $day++) {
        $this->travel(1)->day();
        $checkin($user->fresh());
    }

    $user->refresh();
    // 7 daily check-ins (2 each) + one 7-day streak bonus (10) = 24.
    expect($user->contribution_points)->toBe(24)
        ->and($user->checkin_streak)->toBe(7);
});

test('a broken streak resets to one', function () {
    $user = User::factory()->create();
    $checkin = app(RecordDailyCheckin::class);

    $checkin($user->fresh());
    $this->travel(3)->days(); // skip days
    $checkin($user->fresh());

    expect($user->fresh()->checkin_streak)->toBe(1);
});

test('a new sign-up is credited to the referrer, awarded on verification', function () {
    $referrer = User::factory()->create(['username' => 'inviter']);

    session(['referral' => 'inviter']);
    $referred = app(CreateNewUser::class)->create([
        'name' => 'New Person',
        'username' => 'newbie',
        'email' => 'newbie@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($referred->referred_by_user_id)->toBe($referrer->id)
        ->and($referrer->fresh()->contribution_points)->toBe(0); // not until verified

    event(new Verified($referred));
    expect($referrer->fresh()->contribution_points)->toBe(25);

    // Idempotent — re-verifying doesn't double the referrer's points.
    event(new Verified($referred));
    expect($referrer->fresh()->contribution_points)->toBe(25);
});

test('an unknown referral handle is ignored', function () {
    session(['referral' => 'nobody-here']);

    $created = app(CreateNewUser::class)->create([
        'name' => 'Loner',
        'username' => 'loner',
        'email' => 'loner@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($created->referred_by_user_id)->toBeNull();
});

test('the rankings page lists the ways to earn', function () {
    $this->get('/rankings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rankings')
            ->has('earn', 11)
            ->where('earn.0.label', 'Report a missing set') // richest first (40 pts)
            ->where('referralHandle', null));
});
