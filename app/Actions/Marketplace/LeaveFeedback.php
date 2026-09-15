<?php

namespace App\Actions\Marketplace;

use App\Enums\DealStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceFeedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rate the other party to a completed deal.
 *
 * Only ever from a deal — there is no route to free-floating feedback, which is
 * what keeps ratings from turning into a comment section that anyone can post to
 * about anyone.
 *
 * Escrow feedback is marked verified because an outside party held the money and
 * saw the delivery. Direct feedback is two people agreeing, shown but weighted
 * less, and the average reflects that.
 */
class LeaveFeedback
{
    /** A verified rating is worth this many ordinary ones in the average. */
    private const VERIFIED_WEIGHT = 2;

    public function __invoke(MarketplaceDeal $deal, User $rater, int $rating, ?string $comment = null): MarketplaceFeedback
    {
        if ($deal->status !== DealStatus::Complete) {
            throw new RuntimeException('You can only rate a completed deal.');
        }

        if (! $deal->includes($rater)) {
            throw new RuntimeException('Only the buyer or seller can rate this deal.');
        }

        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('A rating runs from 1 to 5.');
        }

        $ratee = $rater->id === $deal->buyer_id ? $deal->seller : $deal->buyer;

        return DB::transaction(function () use ($deal, $rater, $ratee, $rating, $comment) {
            $feedback = MarketplaceFeedback::updateOrCreate(
                ['marketplace_deal_id' => $deal->id, 'rater_id' => $rater->id],
                [
                    'ratee_id' => $ratee->id,
                    'rating' => $rating,
                    'comment' => $comment ? mb_substr(trim($comment), 0, 500) : null,
                    'verified' => $deal->type->isVerified(),
                ],
            );

            $this->recomputeAverage($ratee);

            return $feedback;
        });
    }

    /**
     * Recompute the stored average, counting a verified rating twice.
     *
     * Stored rather than derived because it is read on every listing tile and
     * profile card; recomputing it per render would be the most expensive query
     * on the busiest page.
     */
    private function recomputeAverage(User $ratee): void
    {
        $row = MarketplaceFeedback::query()
            ->where('ratee_id', $ratee->id)
            ->selectRaw(
                'SUM(rating * (CASE WHEN verified = 1 THEN ? ELSE 1 END)) AS total,
                 SUM(CASE WHEN verified = 1 THEN ? ELSE 1 END) AS weight',
                [self::VERIFIED_WEIGHT, self::VERIFIED_WEIGHT],
            )
            ->first();

        $weight = (float) ($row->weight ?? 0);

        $ratee->forceFill([
            'avg_rating' => $weight > 0 ? round((float) $row->total / $weight, 2) : null,
        ])->save();
    }
}
