<?php

namespace App\Http\Resources;

use App\Models\SaleObservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleObservation
 */
class SaleObservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $raw = $this->raw ?? [];

        return [
            'price' => $this->price,            // integer cents
            'currency' => $this->currency,
            'venue' => $this->venue->value,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'sold_on' => $this->observed_at?->toDateString(),
            'title' => $raw['title'] ?? null,
            'url' => $raw['url'] ?? null,
            'is_outlier' => (bool) $this->is_outlier,
            'is_synthetic' => (bool) $this->is_synthetic,
        ];
    }
}
