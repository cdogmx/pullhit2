<?php

namespace App\Actions\Grading;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Support\Grading\GradeAdvisor;
use App\Support\Grading\SubmissionTiers;
use Illuminate\Support\Collection;

/**
 * Assembles the real CardFoo data behind a "grade it or sell it raw?" decision
 * for one single: its Near Mint (raw) value and trend, its graded values (PSA 10
 * / 9 / 8 — real comps where we have them, modeled from the 10 where we don't),
 * the grading costs, and the GradeAdvisor's expected-value verdict. Fed to the
 * Sensei as context and shown to the user as the stats card. Read-only.
 *
 * The advice is null (and the card page hides the tool) when we lack the two
 * anchors it needs: a raw value and a PSA 10 value.
 */
class BuildGradingDossier
{
    public function __construct(protected GradeAdvisor $advisor) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(CatalogItem $item): array
    {
        $item->loadMissing('set');
        $values = $item->marketValues()
            ->whereIn('state_key', ['NM', 'psa-10', 'psa-9', 'psa-8'])
            ->get()
            ->keyBy('state_key');

        $raw = $values->get('NM');
        $rawCents = $raw?->median;

        $graded = $this->gradedValues($values, $rawCents);
        $advice = ($rawCents !== null && ($graded['10']['value'] ?? null) !== null)
            ? $this->advisor->advise($rawCents, [
                '10' => $graded['10']['value'],
                '9' => $graded['9']['value'],
                '8' => $graded['8']['value'],
            ])->toArray()
            : null;

        $attributes = $item->getAttribute('attributes') ?? [];

        // With no raw value there is nothing to measure the cap against, so the
        // cheapest tier is a floor rather than a quote — keep the money, drop
        // the service level, and the Sensei stops naming one it cannot know.
        $cost = SubmissionTiers::costFor((int) ($rawCents ?? 0));

        if ($rawCents === null) {
            $cost['tier'] = null;
            $cost['turnaround'] = null;
        }

        return [
            'kind' => 'grade',
            'card' => [
                'id' => $item->id,
                'name' => $item->display_name ?? $item->name,
                'number' => $item->number,
                'image' => $item->primary_image_path,
                'set' => $item->set?->name,
                'rarity' => $attributes['rarity'] ?? null,
            ],
            'raw' => $raw ? [
                'value' => $rawCents,
                'n_sales' => $raw->n_sales,
                'confidence' => round((float) $raw->confidence, 2),
                'is_estimated' => (bool) $raw->is_estimated,
                'trend_30d' => $raw->trend_30d,
                'trend_7d' => $raw->trend_7d,
            ] : null,
            'graded' => $graded,
            // Priced at the tier this card's raw value actually qualifies for,
            // the same one the advisor spends against — the Sensei is handed
            // these numbers verbatim, so a flat fee here becomes a flat fee
            // said out loud next to a verdict computed from the real one.
            'costs' => [
                'fee' => $cost['per_card'],
                'shipping' => $cost['shipping'],
                'tier' => $cost['tier'],
                'turnaround' => $cost['turnaround'],
                'sale_fee_pct' => (float) config('grading.sale_fee_pct'),
            ],
            'advice' => $advice,
            'currency' => 'USD',
        ];
    }

    /**
     * PSA 10/9/8 values: real comps where present, otherwise modeled as a fraction
     * of the PSA 10 (flagged estimated). Each entry is null-value when we have
     * neither a real comp nor a 10 to model from.
     *
     * @param  Collection<string, MarketValue>  $values
     * @return array<string, array{value: ?int, n_sales: ?int, estimated: bool}>
     */
    protected function gradedValues(Collection $values, ?int $rawCents): array
    {
        $ten = $values->get('psa-10');
        $tenValue = $ten?->median;

        $out = [
            '10' => [
                'value' => $tenValue,
                'n_sales' => $ten?->n_sales,
                'estimated' => $ten ? (bool) $ten->is_estimated : true,
            ],
        ];

        $multipliers = (array) config('grading.modeled_grade_multiplier');
        foreach (['9', '8'] as $grade) {
            $real = $values->get("psa-{$grade}");
            if ($real !== null) {
                $out[$grade] = [
                    'value' => $real->median,
                    'n_sales' => $real->n_sales,
                    'estimated' => (bool) $real->is_estimated,
                ];

                continue;
            }

            // Model it off the 10 when we have one; else null.
            $modeled = $tenValue !== null && isset($multipliers[$grade])
                ? (int) round($tenValue * (float) $multipliers[$grade])
                : null;
            $out[$grade] = ['value' => $modeled, 'n_sales' => null, 'estimated' => true];
        }

        return $out;
    }
}
