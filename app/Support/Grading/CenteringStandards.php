<?php

namespace App\Support\Grading;

/**
 * What each grading company's published centering tolerance allows.
 *
 * Centering is the one attribute the companies put numbers on, and the numbers
 * differ — so the same card is a 10 on centering to one and a 9 to another.
 * That is worth saying out loud, because it is a real decision: where to send
 * a card, and whether to send it at all.
 *
 * Front and back are judged SEPARATELY, against their own limits. Every
 * standard is far more forgiving of the back than the front — PSA allows 55/45
 * on the face and 75/25 behind it — so taking the worse of the two, which is
 * right for a weakest-link condition score, would fail cards here that no
 * grader would fail.
 *
 * This says nothing about corners, edges or surface. A card can pass every
 * centering tolerance listed and still grade poorly on the things a photograph
 * cannot show, and the caveats elsewhere say so.
 */
class CenteringStandards
{
    /**
     * Every company we hold tolerances for, best grade first per company.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values((array) config('grading.centering_standards', []));
    }

    /**
     * The best grade each company's centering tolerance permits.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function assess(?Centering $front, ?Centering $back): array
    {
        if ($front === null && $back === null) {
            return [];
        }

        $out = [];

        foreach (self::all() as $company) {
            $out[] = self::forCompany($company, $front, $back) + [
                'company' => $company['name'],
                'source' => $company['source'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    private static function forCompany(array $company, ?Centering $front, ?Centering $back): array
    {
        foreach ((array) ($company['grades'] ?? []) as $grade => $limits) {
            $frontOk = $front === null || self::within($front, (float) ($limits['front'] ?? 50));
            $backOk = $back === null || self::within($back, (float) ($limits['back'] ?? 50));

            if ($frontOk && $backOk) {
                return [
                    'grade' => (string) $grade,
                    'label' => $limits['label'] ?? null,
                    // Which side is closest to costing the card this grade —
                    // the one to re-measure if the answer looks wrong.
                    'limited_by' => self::tighter($front, $back, $limits),
                    // Null means "we did not see this side", not "it passed".
                    'judged' => array_values(array_filter([
                        $front !== null ? 'front' : null,
                        $back !== null ? 'back' : null,
                    ])),
                ];
            }
        }

        return [
            'grade' => null,
            'label' => 'below the listed tolerances',
            'limited_by' => self::tighter($front, $back, []),
            'judged' => array_values(array_filter([
                $front !== null ? 'front' : null,
                $back !== null ? 'back' : null,
            ])),
        ];
    }

    /**
     * Is this side inside a tolerance written as its larger share?
     *
     * A "55/45" limit means neither axis may exceed 55 on its wider side, which
     * is the same as saying it is within 5 points of centre.
     */
    private static function within(Centering $side, float $limit): bool
    {
        return $side->worstDeviation() <= ($limit - 50.0) + 1e-9;
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private static function tighter(?Centering $front, ?Centering $back, array $limits): ?string
    {
        // Measured as headroom against each side's OWN limit, not as raw
        // deviation: a back at 60/40 is comfortable where a front at 58/42 is
        // nearly out, and the front is the one to look at again.
        $slack = function (?Centering $side, string $key) use ($limits): ?float {
            if ($side === null) {
                return null;
            }

            $limit = (float) ($limits[$key] ?? 55);

            return ($limit - 50.0) - $side->worstDeviation();
        };

        $frontSlack = $slack($front, 'front');
        $backSlack = $slack($back, 'back');

        if ($frontSlack === null) {
            return $backSlack === null ? null : 'back';
        }

        if ($backSlack === null) {
            return 'front';
        }

        return $frontSlack <= $backSlack ? 'front' : 'back';
    }
}
