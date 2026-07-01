<?php

namespace App\Actions\RipOrKeep;

use App\Actions\Valuation\PriceHistory;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use Illuminate\Support\Carbon;

/**
 * Assembles the real CardFoo data behind a "rip or keep" decision for one sealed
 * product: its current sealed value + price trend, its set's age / in-print
 * status, and the "rip upside" — the set's top chase singles (with the honest
 * caveat that we don't have exact pull rates). Fed to the Sensei as context and
 * shown to the user as the stats card. Read-only.
 */
class BuildSealedDossier
{
    public function __construct(protected PriceHistory $history) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(CatalogItem $item): array
    {
        $item->loadMissing('set', 'defaultMarketValue');
        $attributes = $item->getAttribute('attributes') ?? [];
        $sealed = $item->defaultMarketValue;

        $trend = $this->trend($this->history($item)['points']);

        // The set's singles, richest first — the "what could I pull" upside.
        $singles = CatalogItem::query()
            ->where('set_id', $item->set_id)
            ->where('item_type', ItemType::Single->value)
            ->with('defaultMarketValue')
            ->get()
            ->map(fn (CatalogItem $c) => [
                'name' => $c->display_name ?? $c->name,
                'value' => $c->defaultMarketValue?->median,
            ])
            ->filter(fn (array $r) => $r['value'] !== null)
            ->sortByDesc('value')
            ->values();

        $values = $singles->pluck('value')->all();

        return [
            'product' => [
                'id' => $item->id,
                'name' => $item->display_name ?? $item->name,
                'image' => $item->primary_image_path,
                'sealed_type' => $attributes['sealed_type'] ?? null,
                'pack_count' => $attributes['pack_count'] ?? null,
            ],
            'sealed_value' => $sealed?->median,
            'confidence' => $sealed ? round((float) $sealed->confidence, 2) : null,
            'is_estimated' => (bool) ($sealed?->is_estimated ?? true),
            'currency' => 'USD',
            'trend' => $trend,
            'set' => [
                'name' => $item->set?->name,
                'released_at' => $item->set?->released_at?->toDateString(),
                'age_years' => $item->set?->released_at
                    ? round($item->set->released_at->diffInDays(Carbon::now()) / 365, 1)
                    : null,
                // MSRP or live retailer links present → still buyable at retail.
                'in_print' => $item->msrp !== null || ! empty($item->retailer_links),
            ],
            'chase' => [
                'top' => $singles->take(8)->all(),
                'single_count' => $singles->count(),
                'max_single' => $values[0] ?? null,
                'median_single' => $this->median($values),
                'count_over_50' => count(array_filter($values, fn ($v) => $v >= 5_000)),
            ],
        ];
    }

    /** @return array{points: array<int, array{t: string, price: int, n: int}>, estimated: bool} */
    private function history(CatalogItem $item): array
    {
        return ($this->history)($item);
    }

    /**
     * Overall % change across the available weekly-median history, with the span
     * it covers — the headline "is sealed appreciating?" signal.
     *
     * @param  array<int, array{t: string, price: int, n: int}>  $points
     * @return array{pct: float, days: int, direction: string}|null
     */
    private function trend(array $points): ?array
    {
        if (count($points) < 2) {
            return null;
        }

        $first = $points[0];
        $last = $points[count($points) - 1];

        if ($first['price'] <= 0) {
            return null;
        }

        $pct = round((($last['price'] - $first['price']) / $first['price']) * 100, 1);
        $days = (int) round((strtotime($last['t']) - strtotime($first['t'])) / 86_400);

        return [
            'pct' => $pct,
            'days' => $days,
            'direction' => $pct > 2 ? 'up' : ($pct < -2 ? 'down' : 'flat'),
        ];
    }

    /** @param  array<int, int>  $sorted  values (already high→low) */
    private function median(array $sorted): ?int
    {
        $n = count($sorted);

        if ($n === 0) {
            return null;
        }

        return $n % 2 === 1
            ? (int) $sorted[intdiv($n, 2)]
            : (int) round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }
}
