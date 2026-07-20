<?php

namespace App\Http\Resources;

use App\Models\MarketValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketValue
 */
class MarketValueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'state_key' => $this->state_key,
            'label' => $this->label(),
            'condition' => $this->condition?->value,
            'grade' => $this->grade,
            'grading_company' => $this->whenLoaded('gradingCompany', fn () => $this->gradingCompany
                ? ['slug' => $this->gradingCompany->slug, 'name' => $this->gradingCompany->name]
                : null),
            // Money in integer minor units (cents); formatted at the edge.
            'median' => $this->median,
            'p25' => $this->p25,
            'p75' => $this->p75,
            'low' => $this->low,
            'high' => $this->high,
            // Sold (median) vs current asks vs the blended combined figure.
            'for_sale' => $this->for_sale,
            'for_sale_n' => $this->for_sale_n,
            'combined' => $this->combined,
            'n_sales' => $this->n_sales,
            'confidence' => round($this->confidence, 3),
            'confidence_label' => $this->confidenceLabel(),
            // Single-seller dominance of the comps (null = too few to judge).
            'top_seller_share' => $this->top_seller_share !== null ? round($this->top_seller_share, 3) : null,
            'is_estimated' => (bool) $this->is_estimated,
            // Recent sales up sharply on real, non-manipulated data.
            'is_surging' => $this->isSurging(),
            'half_life_days' => $this->half_life_days,
            'trend_1d' => $this->trend_1d,
            'trend_7d' => $this->trend_7d,
            'trend_30d' => $this->trend_30d,
            'currency' => $this->currency,
            'computed_at' => $this->computed_at?->toIso8601String(),
        ];
    }

    private function label(): string
    {
        if ($this->grading_company_id !== null) {
            $company = $this->relationLoaded('gradingCompany') && $this->gradingCompany
                ? $this->gradingCompany->slug
                : explode('-', (string) $this->state_key)[0];

            $grade = rtrim(rtrim(sprintf('%.1f', (float) $this->grade), '0'), '.');

            return strtoupper($company)." {$grade}";
        }

        return $this->condition?->label() ?? 'Ungraded';
    }

    private function confidenceLabel(): string
    {
        return match (true) {
            $this->confidence < 0.4 => 'Low',
            $this->confidence < 0.7 => 'Medium',
            default => 'High',
        };
    }
}
