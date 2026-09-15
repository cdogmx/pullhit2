<?php

use App\Actions\Marketplace\CompleteDeal;
use App\Actions\Marketplace\LeaveFeedback;
use App\Actions\Marketplace\LogDeal;
use App\Actions\Marketplace\OpenThread;
use App\Enums\DealStatus;
use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceListing;
use App\Models\User;

beforeEach(function () {
    $this->seller = User::factory()->create(['username' => 'seller']);
    $this->buyer = User::factory()->create(['username' => 'buyer']);
    $this->listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);

    $this->open = app(OpenThread::class);
    $this->deals = app(LogDeal::class);
    $this->complete = app(CompleteDeal::class);
    $this->feedback = app(LeaveFeedback::class);

    $this->thread = ($this->open)($this->listing, $this->buyer);
});

test('contacting a seller twice returns the same conversation', function () {
    // "Contact seller" is a button people press twice. A second thread would
    // split the conversation and leave each side reading a different half.
    $again = ($this->open)($this->listing, $this->buyer);

    expect($again->id)->toBe($this->thread->id);
});

test('a seller cannot open a thread against their own listing', function () {
    expect(fn () => ($this->open)($this->listing, $this->seller))
        ->toThrow(RuntimeException::class);
});

test('a logged deal waits on the other party, not the proposer', function () {
    $deal = $this->deals->propose($this->thread, $this->buyer, 99000);

    expect($deal->status)->toBe(DealStatus::Proposed)
        ->and($deal->awaitingAcceptanceFrom())->toBe($this->seller->id);

    // A seller accepting their own proposal would be a sale with one participant.
    expect(fn () => $this->deals->accept($deal, $this->buyer))
        ->toThrow(RuntimeException::class);
});

test('accepting takes the listing off the market without selling it', function () {
    $deal = $this->deals->propose($this->thread, $this->buyer, 99000);
    $this->deals->accept($deal, $this->seller);

    expect($deal->fresh()->status)->toBe(DealStatus::Accepted)
        // Pending, not sold: the deal can still fall through and the card has to
        // be able to come back.
        ->and($this->listing->fresh()->status)->toBe(ListingStatus::Pending);
});

test('one confirmation is not enough', function () {
    // A single confirmation is somebody describing their own behaviour.
    $deal = $this->deals->propose($this->thread, $this->buyer, 99000);
    $this->deals->accept($deal, $this->seller);

    $this->deals->confirm($deal, $this->buyer, $this->complete);

    expect($deal->fresh()->status)->toBe(DealStatus::Accepted)
        ->and($deal->fresh()->buyer_confirmed_at)->not->toBeNull()
        ->and($this->seller->fresh()->direct_deal_count)->toBe(0);
});

test('both confirmations complete the deal and credit both sides', function () {
    $deal = $this->deals->propose($this->thread, $this->buyer, 99000);
    $this->deals->accept($deal, $this->seller);

    $this->deals->confirm($deal, $this->buyer, $this->complete);
    $this->deals->confirm($deal, $this->seller, $this->complete);

    expect($deal->fresh()->status)->toBe(DealStatus::Complete)
        ->and($this->listing->fresh()->status)->toBe(ListingStatus::Sold)
        ->and($this->buyer->fresh()->direct_deal_count)->toBe(1)
        ->and($this->seller->fresh()->direct_deal_count)->toBe(1)
        // A direct deal is self-reported; it never touches the protected count.
        ->and($this->seller->fresh()->protected_deal_count)->toBe(0);
});

test('confirming twice does not count twice', function () {
    $deal = $this->deals->propose($this->thread, $this->buyer, 99000);
    $this->deals->accept($deal, $this->seller);

    $this->deals->confirm($deal, $this->buyer, $this->complete);
    $this->deals->confirm($deal, $this->buyer, $this->complete);

    expect($deal->fresh()->status)->toBe(DealStatus::Accepted)
        ->and($this->seller->fresh()->direct_deal_count)->toBe(0);
});

test('cancelling puts the card back on the market', function () {
    $deal = $this->deals->propose($this->thread, $this->buyer, 99000);
    $this->deals->accept($deal, $this->seller);
    $this->deals->cancel($deal, $this->seller);

    expect($deal->fresh()->status)->toBe(DealStatus::Cancelled)
        ->and($this->listing->fresh()->status)->toBe(ListingStatus::Active);
});

test('a thread holds one open deal at a time', function () {
    // Two live proposals is a question about which price they agreed, and
    // neither party could answer it from the page.
    $this->deals->propose($this->thread, $this->buyer, 99000);

    expect(fn () => $this->deals->propose($this->thread, $this->seller, 88000))
        ->toThrow(RuntimeException::class);
});

test('a cancelled deal frees the thread for another', function () {
    $first = $this->deals->propose($this->thread, $this->buyer, 99000);
    $this->deals->cancel($first, $this->buyer);

    $second = $this->deals->propose($this->thread, $this->seller, 88000);

    expect($second->status)->toBe(DealStatus::Proposed);
});

test('repeat deals between the same two people stop counting after three', function () {
    // Two people can sell each other the same card back and forth all afternoon.
    // The cap is what stops a vouching loop manufacturing a reputation.
    foreach (range(1, 5) as $i) {
        $listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);
        $thread = ($this->open)($listing, $this->buyer);

        $deal = $this->deals->propose($thread, $this->buyer, 1000 * $i);
        $this->deals->accept($deal, $this->seller);
        $this->deals->confirm($deal, $this->buyer, $this->complete);
        $this->deals->confirm($deal, $this->seller, $this->complete);
    }

    expect(MarketplaceDeal::where('status', DealStatus::Complete)->count())->toBe(5)
        // Five real deals, three counted.
        ->and($this->seller->fresh()->direct_deal_count)->toBe(3)
        ->and($this->buyer->fresh()->direct_deal_count)->toBe(3);
});

test('the cap does not reset when the pair swap roles', function () {
    foreach (range(1, 4) as $i) {
        // Alternate who is selling.
        $sellerIsMe = $i % 2 === 0;
        $owner = $sellerIsMe ? $this->seller : $this->buyer;
        $other = $sellerIsMe ? $this->buyer : $this->seller;

        $listing = MarketplaceListing::factory()->create(['user_id' => $owner->id]);
        $thread = ($this->open)($listing, $other);

        $deal = $this->deals->propose($thread, $other, 1000 * $i);
        $this->deals->accept($deal, $owner);
        $this->deals->confirm($deal, $other, $this->complete);
        $this->deals->confirm($deal, $owner, $this->complete);
    }

    expect($this->seller->fresh()->direct_deal_count)->toBe(3);
});

test('an escrow deal counts as protected and is capped by nothing', function () {
    // Trustap held the money; there is no vouching loop to close.
    foreach (range(1, 5) as $i) {
        $listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);

        $deal = MarketplaceDeal::factory()->escrow()->create([
            'marketplace_listing_id' => $listing->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'proposed_by_id' => $this->buyer->id,
            'status' => DealStatus::Accepted,
        ]);

        ($this->complete)($deal);
    }

    expect($this->seller->fresh()->protected_deal_count)->toBe(5)
        ->and($this->seller->fresh()->direct_deal_count)->toBe(0);
});

test('feedback only comes from a completed deal', function () {
    $deal = $this->deals->propose($this->thread, $this->buyer, 99000);

    expect(fn () => ($this->feedback)($deal, $this->buyer, 5))
        ->toThrow(RuntimeException::class);
});

test('a completed deal lets each side rate the other once', function () {
    $deal = MarketplaceDeal::factory()->complete()->create([
        'marketplace_listing_id' => $this->listing->id,
        'buyer_id' => $this->buyer->id,
        'seller_id' => $this->seller->id,
        'proposed_by_id' => $this->buyer->id,
    ]);

    ($this->feedback)($deal, $this->buyer, 5, 'Shipped fast.');

    expect((float) $this->seller->fresh()->avg_rating)->toBe(5.0)
        ->and($deal->canBeRatedBy($this->buyer))->toBeFalse()
        ->and($deal->canBeRatedBy($this->seller))->toBeTrue();
});

test('a verified rating counts double in the average', function () {
    // An outside party held the money and saw the delivery; two friends agreeing
    // should not move a score as far.
    $direct = MarketplaceDeal::factory()->complete()->create([
        'marketplace_listing_id' => $this->listing->id,
        'buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id,
        'proposed_by_id' => $this->buyer->id, 'type' => DealType::Direct,
    ]);
    $escrow = MarketplaceDeal::factory()->escrow()->complete()->create([
        'marketplace_listing_id' => MarketplaceListing::factory()->create(['user_id' => $this->seller->id]),
        'buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id,
        'proposed_by_id' => $this->buyer->id,
    ]);

    ($this->feedback)($direct, $this->buyer, 1);
    ($this->feedback)($escrow, $this->buyer, 4);

    // (1×1 + 4×2) ÷ (1 + 2) = 3.00, not the 2.50 a flat mean would give.
    expect((float) $this->seller->fresh()->avg_rating)->toBe(3.0);
});

test('a rating outside one to five is refused', function () {
    $deal = MarketplaceDeal::factory()->complete()->create([
        'marketplace_listing_id' => $this->listing->id,
        'buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id,
        'proposed_by_id' => $this->buyer->id,
    ]);

    expect(fn () => ($this->feedback)($deal, $this->buyer, 6))->toThrow(RuntimeException::class);
    expect(fn () => ($this->feedback)($deal, $this->buyer, 0))->toThrow(RuntimeException::class);
});

test('a stranger can neither confirm nor rate a deal', function () {
    $stranger = User::factory()->create();
    $deal = MarketplaceDeal::factory()->complete()->create([
        'marketplace_listing_id' => $this->listing->id,
        'buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id,
        'proposed_by_id' => $this->buyer->id,
    ]);

    expect(fn () => ($this->feedback)($deal, $stranger, 5))->toThrow(RuntimeException::class);
    expect($deal->canBeRatedBy($stranger))->toBeFalse();
});
