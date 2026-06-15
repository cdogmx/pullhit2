<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\User;
use App\Models\WishlistItem;
use App\Notifications\WishlistTargetHit;
use Illuminate\Support\Facades\Notification;

function wishWithValue(int $median, ?int $target, ?string $hitAt = null): WishlistItem
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $item = CatalogItem::factory()->create();
    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'median' => $median,
    ]);

    return WishlistItem::create([
        'user_id' => $user->id, 'catalog_item_id' => $item->id,
        'target_price' => $target, 'target_hit_at' => $hitAt,
    ]);
}

test('it alerts once when a card drops to/below target', function () {
    Notification::fake();
    $wish = wishWithValue(median: 5000, target: 6000); // 5000 <= 6000 → hit

    $this->artisan('wishlist:check-targets')->assertSuccessful();

    expect($wish->fresh()->target_hit_at)->not->toBeNull();
    Notification::assertSentTo($wish->user, WishlistTargetHit::class);

    // Re-running does not re-notify a card that's still at target.
    Notification::fake();
    $this->artisan('wishlist:check-targets');
    Notification::assertNothingSent();
});

test('recovery above target re-arms the alert', function () {
    Notification::fake();
    $wish = wishWithValue(median: 7000, target: 6000, hitAt: now()->toDateTimeString()); // 7000 > 6000 → recovered

    $this->artisan('wishlist:check-targets');

    expect($wish->fresh()->target_hit_at)->toBeNull();
    Notification::assertNothingSent();
});

test('a card above target does not alert', function () {
    Notification::fake();
    wishWithValue(median: 8000, target: 6000); // above target

    $this->artisan('wishlist:check-targets');

    Notification::assertNothingSent();
});

test('no target price means no alert', function () {
    Notification::fake();
    wishWithValue(median: 5000, target: null);

    $this->artisan('wishlist:check-targets');

    Notification::assertNothingSent();
});
