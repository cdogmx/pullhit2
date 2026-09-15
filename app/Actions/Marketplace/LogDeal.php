<?php

namespace App\Actions\Marketplace;

use App\Enums\DealStatus;
use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record that two people have agreed a sale, and then that it happened.
 *
 * Logging is optional — a buyer and seller can settle a card in chat and never
 * tell us, and that is fine. The only thing pulling them back is reputation, so
 * the flow has to be short: one taps "log this deal", the other accepts, both
 * confirm afterwards.
 *
 * Nothing here is verified. A direct deal is two people's word, which is why it
 * takes two of them and why the reputation model counts it separately from an
 * escrow deal that Trustap stood behind.
 */
class LogDeal
{
    /** Proposed by one party, waiting on the other. */
    public function propose(MarketplaceThread $thread, User $proposer, int $priceCents): MarketplaceDeal
    {
        if (! $thread->includes($proposer)) {
            throw new RuntimeException('Only the buyer or seller can log this deal.');
        }

        if ($priceCents < 1) {
            throw new RuntimeException('A deal needs a price.');
        }

        // One open deal per thread. Two live proposals on one conversation is a
        // question about which price they actually agreed, and neither party
        // would be able to answer it from the page.
        if ($thread->deals()->open()->exists()) {
            throw new RuntimeException('There is already a deal in progress for this listing.');
        }

        return DB::transaction(function () use ($thread, $proposer, $priceCents) {
            $deal = MarketplaceDeal::create([
                'marketplace_listing_id' => $thread->marketplace_listing_id,
                'marketplace_thread_id' => $thread->id,
                'buyer_id' => $thread->buyer_id,
                'seller_id' => $thread->seller_id,
                'proposed_by_id' => $proposer->id,
                'type' => DealType::Direct,
                'status' => DealStatus::Proposed,
                'agreed_price_cents' => $priceCents,
                'currency' => $thread->listing->currency ?? 'USD',
            ]);

            return $deal;
        });
    }

    /** The other party agrees. The card comes off the market while it plays out. */
    public function accept(MarketplaceDeal $deal, User $user): MarketplaceDeal
    {
        if ($deal->status !== DealStatus::Proposed) {
            throw new RuntimeException('That deal is no longer waiting to be accepted.');
        }

        // Only the side that did not propose it — otherwise a seller could log a
        // deal and accept it alone, which is a sale with one participant.
        if ($deal->awaitingAcceptanceFrom() !== $user->id) {
            throw new RuntimeException('The other party has to accept this deal.');
        }

        return DB::transaction(function () use ($deal) {
            $deal->forceFill(['status' => DealStatus::Accepted])->save();

            // Off the market, not sold: the deal can still fall through, and the
            // listing has to be able to come back.
            $deal->listing?->forceFill(['status' => ListingStatus::Pending])->save();

            return $deal->refresh();
        });
    }

    /**
     * One side says it completed. When both have, it completed.
     *
     * Deliberately not "the seller marks it shipped and that is that": a single
     * confirmation is somebody describing their own behaviour, and reputation
     * built on that is worth nothing to the next buyer reading it.
     */
    public function confirm(MarketplaceDeal $deal, User $user, CompleteDeal $complete): MarketplaceDeal
    {
        if (! $deal->includes($user)) {
            throw new RuntimeException('Only the buyer or seller can confirm this deal.');
        }

        if ($deal->status !== DealStatus::Accepted) {
            throw new RuntimeException('This deal is not waiting on confirmation.');
        }

        $column = $user->id === $deal->buyer_id ? 'buyer_confirmed_at' : 'seller_confirmed_at';

        if ($deal->{$column} !== null) {
            return $deal;
        }

        $deal->forceFill([$column => now()])->save();

        if ($deal->buyer_confirmed_at && $deal->seller_confirmed_at) {
            return $complete($deal->refresh());
        }

        return $deal->refresh();
    }

    /** Either side walks away while it is still only an agreement. */
    public function cancel(MarketplaceDeal $deal, User $user): MarketplaceDeal
    {
        if (! $deal->includes($user)) {
            throw new RuntimeException('Only the buyer or seller can cancel this deal.');
        }

        if (! $deal->status->isCancellable()) {
            throw new RuntimeException('This deal can no longer be cancelled here.');
        }

        return DB::transaction(function () use ($deal) {
            $deal->forceFill(['status' => DealStatus::Cancelled])->save();

            // Back on the market, unless the seller has since done something
            // else with it.
            if ($deal->listing?->status === ListingStatus::Pending) {
                $deal->listing->forceFill(['status' => ListingStatus::Active])->save();
            }

            return $deal->refresh();
        });
    }
}
