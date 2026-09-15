<?php

namespace App\Actions\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceThread;
use App\Models\User;
use RuntimeException;

/**
 * Open (or return) the one conversation a buyer has about a listing.
 *
 * Idempotent on purpose: "Contact seller" is a button people press twice. A
 * second thread for the same pair would split the conversation in half and leave
 * each side reading a different one.
 */
class OpenThread
{
    public function __invoke(MarketplaceListing $listing, User $buyer): MarketplaceThread
    {
        if ($listing->user_id === $buyer->id) {
            throw new RuntimeException('You cannot message yourself about your own listing.');
        }

        return MarketplaceThread::firstOrCreate(
            [
                'marketplace_listing_id' => $listing->id,
                'buyer_id' => $buyer->id,
            ],
            [
                // Denormalised from the listing so the thread survives the
                // listing changing hands or being removed — the conversation is
                // between these two people whatever happens to the card.
                'seller_id' => $listing->user_id,
            ],
        );
    }
}
