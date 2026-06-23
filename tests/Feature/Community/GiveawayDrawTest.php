<?php

use App\Actions\Community\DrawGiveaway;
use App\Enums\ContributionType;
use App\Models\Contribution;
use App\Models\Giveaway;
use App\Models\User;
use App\Notifications\GiveawayWon;
use Illuminate\Support\Facades\Notification;

function entrant(string $username, int $points): User
{
    $user = User::factory()->create(['username' => $username, 'contribution_points' => $points]);
    Contribution::create([
        'user_id' => $user->id,
        'type' => ContributionType::MissingCard,
        'points' => $points,
        'description' => 'test',
    ]);

    return $user;
}

function thisMonthsGiveaway(): Giveaway
{
    return Giveaway::create([
        'period' => now()->format('Y-m'),
        'title' => 'Test giveaway',
        'prize' => 'A booster box',
    ]);
}

test('the draw picks an eligible entrant, snapshots totals, and notifies them', function () {
    Notification::fake();
    $winner = entrant('solo', 30);
    $giveaway = thisMonthsGiveaway();

    $drawn = app(DrawGiveaway::class)($giveaway);

    expect($drawn?->id)->toBe($winner->id);

    $giveaway->refresh();
    expect($giveaway->status)->toBe(Giveaway::DRAWN)
        ->and($giveaway->winner_user_id)->toBe($winner->id)
        ->and($giveaway->winner_entries)->toBe(30)
        ->and($giveaway->total_entries)->toBe(30)
        ->and($giveaway->entrant_count)->toBe(1)
        ->and($giveaway->drawn_at)->not->toBeNull();

    Notification::assertSentTo($winner, GiveawayWon::class);
});

test('the winner is always one of the entrants', function () {
    Notification::fake();
    $a = entrant('alpha', 5);
    $b = entrant('bravo', 500);
    $giveaway = thisMonthsGiveaway();

    $drawn = app(DrawGiveaway::class)($giveaway);

    expect([$a->id, $b->id])->toContain($drawn?->id);
    expect($giveaway->refresh()->total_entries)->toBe(505);
});

test('a giveaway with no entries draws no winner and stays open', function () {
    Notification::fake();
    $giveaway = thisMonthsGiveaway();

    $drawn = app(DrawGiveaway::class)($giveaway);

    // Nothing to draw — leave it open so it can be drawn once entries arrive.
    expect($drawn)->toBeNull();
    expect($giveaway->refresh()->status)->toBe(Giveaway::OPEN)
        ->and($giveaway->winner_user_id)->toBeNull();
    Notification::assertNothingSent();
});

test('a drawn giveaway is not redrawn', function () {
    Notification::fake();
    $winner = entrant('solo', 10);
    $giveaway = thisMonthsGiveaway();

    app(DrawGiveaway::class)($giveaway);
    $again = app(DrawGiveaway::class)($giveaway);

    expect($again?->id)->toBe($winner->id);
    Notification::assertSentToTimes($winner, GiveawayWon::class, 1); // not re-notified
});
