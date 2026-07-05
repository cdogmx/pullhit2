<?php

namespace App\Actions\RipOrKeep;

use App\Actions\Valuation\PriceHistory;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\SetPullOdd;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

        // The set's priced singles (name + rarity + value) — the raw material for
        // both the "what could I pull" chase list and the modeled rip EV.
        $singles = CatalogItem::query()
            ->where('set_id', $item->set_id)
            ->where('item_type', ItemType::Single->value)
            ->with('defaultMarketValue')
            ->get()
            ->map(fn (CatalogItem $c) => [
                'name' => $c->display_name ?? $c->name,
                'rarity' => $c->getAttribute('attributes')['rarity'] ?? null,
                'value' => $c->defaultMarketValue?->median,
            ])
            ->filter(fn (array $r) => $r['value'] !== null)
            ->values();

        $values = $singles->pluck('value')->sortDesc()->values()->all();
        $topChase = $singles->sortByDesc('value')->take(8)
            ->map(fn (array $r) => ['name' => $r['name'], 'value' => $r['value']])
            ->values()->all();

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
                // Known MSRP or an active tracked retailer link → still buyable at
                // retail (the deals tracker is the single source of buy links).
                'in_print' => $item->msrp !== null
                    || $item->trackedProducts()->whereHas('links', fn ($q) => $q->where('is_active', true))->exists(),
            ],
            'chase' => [
                'top' => $topChase,
                'single_count' => $singles->count(),
                'max_single' => $values[0] ?? null,
                'median_single' => $this->median($values),
                'count_over_50' => count(array_filter($values, fn ($v) => $v >= 5_000)),
            ],
            // Modeled expected value of opening, when we have researched pack odds.
            'rip_ev' => $this->ripEv($item, $singles, $attributes),
        ];
    }

    /**
     * Expected value of ripping, modeled from researched per-rarity pack odds ×
     * the mean value of that rarity's singles in the set. Null when the set has
     * no stored odds. Honest about scope: prices the chase rarities, not bulk.
     *
     * @param  Collection<int, array{name: string, rarity: ?string, value: int}>  $singles
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function ripEv(CatalogItem $item, Collection $singles, array $attributes): ?array
    {
        $odds = SetPullOdd::where('set_id', $item->set_id)->get();

        if ($odds->isEmpty()) {
            return null;
        }

        $meanByRarity = $singles->whereNotNull('rarity')
            ->groupBy('rarity')
            ->map(fn (Collection $g) => (int) round($g->avg('value')));

        $perPack = 0.0;
        $breakdown = [];

        foreach ($odds as $o) {
            $mean = $meanByRarity[$o->rarity] ?? null;

            if ($mean === null) {
                continue;
            }

            $perPack += $o->per_pack_prob * $mean;
            $breakdown[] = [
                'rarity' => $o->rarity,
                'per_pack_prob' => $o->per_pack_prob,
                'mean_value' => $mean,
            ];
        }

        if ($breakdown === []) {
            return null;
        }

        $packs = $this->packsPerBox($attributes);

        return [
            'ev_per_pack' => (int) round($perPack),
            'packs' => $packs,
            'ev_total' => $packs ? (int) round($perPack * $packs) : null,
            'breakdown' => $breakdown,
            'sources' => $odds->pluck('source')->filter()->unique()->values()->take(4)->all(),
            'min_confidence' => $odds->min('confidence'),
        ];
    }

    /** Packs the product yields — explicit count, else a per-type default. */
    private function packsPerBox(array $attributes): ?int
    {
        if (! empty($attributes['pack_count'])) {
            return (int) $attributes['pack_count'];
        }

        return match ($attributes['sealed_type'] ?? null) {
            'booster_box' => 36,
            'elite_trainer_box' => 9,
            'booster_bundle' => 6,
            default => null,
        };
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
