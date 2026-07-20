<?php

namespace App\Support\Valuation;

/**
 * Blends the sold-based value with the "for sale" (lowest realistic ask) value
 * into one combined figure. Sold price is the truth — a realized transaction —
 * so it anchors the result; the current ask only nudges it.
 *
 * Asks ABOVE the sold median barely move it: listing high costs nothing, so an
 * expensive ask is weak evidence. Asks BELOW it are a genuine softening signal
 * (sellers are undercutting the last sold price to move inventory), so they pull
 * the figure down harder. Pure (cents in, cents out).
 */
class CombinedValue
{
    /**
     * @param  array<string, mixed>|null  $config  defaults to config('valuation.for_sale')
     * @return int|null combined cents, or null when neither input exists
     */
    public static function blend(?int $sold, ?int $forSale, ?array $config = null): ?int
    {
        if ($sold === null) {
            return $forSale;
        }

        if ($forSale === null) {
            return $sold;
        }

        $config ??= config('valuation.for_sale');
        $weight = $forSale >= $sold
            ? (float) ($config['combine_up_weight'] ?? 0.15)
            : (float) ($config['combine_down_weight'] ?? 0.5);

        return (int) round($sold + ($forSale - $sold) * $weight);
    }
}
