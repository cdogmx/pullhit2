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
            'n_sales' => $this->n_sales,
            'confidence' => round($this->confidence, 3),
            'confidence_label' => $this->confidenceLabel(),
            'is_estimated' => (bool) $this->is_estimated,
            'half_life_days' => $this->half_life_days,
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
