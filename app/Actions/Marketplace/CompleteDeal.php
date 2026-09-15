<?php

namespace App\Actions\Marketplace;

use App\Enums\DealStatus;
use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Models\MarketplaceDeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A deal finished: close the listing and move the reputation.
 *
 * The one place a deal count changes, whichever path got here — both sides
 * confirming a direct sale, or Trustap releasing funds on a protected one. A
 * second place to increment a counter is a second place for it to drift.
 */
class CompleteDeal
{
    /**
     * Direct deals between the same two people stop counting after this many.
     *
     * Two people can sell each other the same card back and forth all afternoon.
     * Capping the pair is what stops a vouching loop manufacturing a reputation,
     * and it costs nothing honest: a genuine pair of repeat traders loses only
     * the count, never the feedback.
     */
    private const PAIR_CAP = 3;

    public function __invoke(MarketplaceDeal $deal): MarketplaceDeal
    {
        return DB::transaction(function () use ($deal) {
            $deal->forceFill([
                'status' => DealStatus::Complete,
                'completed_at' => now(),
            ])->save();

            $deal->listing?->forceFill(['status' => ListingStatus::Sold])->save();

            $this->credit($deal);

            return $deal->refresh();
        });
    }

    /** Move the denormalised counters both sides are judged on. */
    private function credit(MarketplaceDeal $deal): void
    {
        $column = $deal->type === DealType::Escrow ? 'protected_deal_count' : 'direct_deal_count';

        foreach ([$deal->buyer, $deal->seller] as $user) {
            if (! $user instanceof User) {
                continue;
            }

            if ($deal->type === DealType::Direct && $this->pairIsCapped($deal)) {
                continue;
            }

            $user->increment($column);
        }
    }

    /**
     * Whether this pair has already had all the direct-deal credit they get.
     *
     * Counted across completed direct deals between the two, in either
     * direction — swapping who plays buyer does not reset it.
     */
    private function pairIsCapped(MarketplaceDeal $deal): bool
    {
        $a = $deal->buyer_id;
        $b = $deal->seller_id;

        $completed = MarketplaceDeal::query()
            ->where('type', DealType::Direct)
            ->where('status', DealStatus::Complete)
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('buyer_id', $a)->where('seller_id', $b))
                ->orWhere(fn ($p) => $p->where('buyer_id', $b)->where('seller_id', $a)))
            ->count();

        // This deal is already saved as complete, so the cap is reached when the
        // count passes it rather than meets it.
        return $completed > self::PAIR_CAP;
    }
}
