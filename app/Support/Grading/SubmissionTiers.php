<?php

namespace App\Support\Grading;

/**
 * The service levels a grading company sells, and which one a card must use.
 *
 * A tier is not a choice so much as a consequence: every company caps the
 * declared value each level will accept, so a card worth more than the cheap
 * tier allows has to go up a level whether the owner likes it or not. That
 * matters to the only question this system asks about grading — is it worth
 * it — because the fee is most of the answer on a cheap card. A flat guess
 * says yes to cards that would lose money at their real tier.
 *
 * PSA labels the cap "Max Insured Value", and it is measured against the card
 * being sent: the raw value, not the hoped-for graded one.
 * Sending a card under-declared to reach a cheaper tier is against every
 * company's terms and caps what they will pay if they lose it, so the cheapest
 * ELIGIBLE tier is the honest answer rather than the cheapest tier.
 */
class SubmissionTiers
{
    /**
     * Every tier held for a company, cheapest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(string $company = 'psa', ?array $config = null): array
    {
        $config ??= (array) config('grading');
        $tiers = (array) ($config['submission_tiers'][$company]['tiers'] ?? []);

        usort($tiers, fn ($a, $b) => ($a['fee'] ?? 0) <=> ($b['fee'] ?? 0));

        return $tiers;
    }

    /**
     * The cheapest tier that will accept a card of this value.
     *
     * Null when no tiers are configured, or when the card is worth more than
     * every tier listed — in which case saying nothing is better than quoting
     * a level that would reject it.
     *
     * @return array<string, mixed>|null
     */
    public static function cheapestFor(int $declaredValueCents, string $company = 'psa', ?array $config = null): ?array
    {
        foreach (self::for($company, $config) as $tier) {
            $cap = $tier['max_insured_value'] ?? null;

            // A tier with no cap is the top of the range and takes anything.
            if ($cap === null || $declaredValueCents <= (int) round((float) $cap * 100)) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * What grading this card actually costs, in cents.
     *
     * The configured tier where one fits, and the flat fallback otherwise —
     * so this is safe to call before any tier has been filled in, and starts
     * giving better answers the moment one is.
     *
     * @return array{cents: int, tier: ?string, turnaround: ?string, per_card: int, shipping: int}
     */
    public static function costFor(int $declaredValueCents, string $company = 'psa', ?array $config = null): array
    {
        $config ??= (array) config('grading');

        $shipping = (int) round((float) ($config['shipping'] ?? 0) * 100);
        $tier = self::cheapestFor($declaredValueCents, $company, $config);

        $perCard = $tier !== null
            ? (int) round((float) $tier['fee'] * 100)
            : (int) round((float) ($config['fee'] ?? 0) * 100);

        return [
            'cents' => $perCard + $shipping,
            'tier' => $tier['name'] ?? null,
            'turnaround' => $tier['turnaround'] ?? null,
            'per_card' => $perCard,
            'shipping' => $shipping,
        ];
    }
}
