<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MarketValue;
use App\Models\ProductLine;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Biggest movers — the cards whose real (non-estimated) ungraded value moved
 * most over the last 30 days, split into gainers and losers. A price floor and
 * a minimum comp count keep penny-card noise out of the rankings. Optionally
 * scoped to one product line via ?line=slug.
 */
class MoversController extends Controller
{
    /** Only rank cards worth at least this (cents) — filters out noisy penny swings. */
    private const MIN_MEDIAN_CENTS = 300;

    /** Require at least this many sold comps behind the value + trend. */
    private const MIN_SALES = 5;

    /**
     * Reject implausible swings so the page stays credible — a thin prior window
     * produces artifact "moves" that aren't real market action. Bounds are
     * asymmetric: a card can genuinely multiply, but a 30-day drop past ~75% is
     * almost always a bad prior (and shows a change bigger than the value).
     */
    private const GAIN_CAP = 300.0;

    private const LOSS_FLOOR = -60.0;

    private const LIMIT = 24;

    public function __invoke(Request $request): Response
    {
        $line = $request->string('line')->toString() ?: null;
        $lineId = $line
            ? ProductLine::where('slug', $line)->value('id')
            : null;

        return Inertia::render('movers', [
            'gainers' => $this->movers('desc', $lineId),
            'losers' => $this->movers('asc', $lineId),
            'lines' => $this->lines(),
            'line' => $lineId ? $line : null,
            'meta' => [
                'title' => 'Biggest movers — 30-day price gainers & losers | CardFoo',
                'description' => 'The cards moving most on real sold-price data: the biggest 30-day gainers and losers, with current value and change.',
            ],
        ]);
    }

    /**
     * @param  'asc'|'desc'  $direction  desc = gainers (largest rise first), asc = losers
     * @return array<int, array<string, mixed>>
     */
    private function movers(string $direction, ?int $lineId): array
    {
        $gainers = $direction === 'desc';

        return MarketValue::query()
            ->whereNull('grading_company_id')
            ->where('is_estimated', false)
            ->where('median', '>=', self::MIN_MEDIAN_CENTS)
            ->where('n_sales', '>=', self::MIN_SALES)
            ->whereNotNull('trend_30d')
            ->when(
                $gainers,
                fn (Builder $q) => $q->whereBetween('trend_30d', [0.01, self::GAIN_CAP]),
                fn (Builder $q) => $q->whereBetween('trend_30d', [self::LOSS_FLOOR, -0.01]),
            )
            ->whereHas('catalogItem', fn (Builder $q) => $q
                ->whereNotNull('primary_image_path')
                ->when($lineId, fn (Builder $q) => $q->where('product_line_id', $lineId)))
            ->with(['catalogItem' => fn ($q) => $q->with('set:id,name,slug', 'productLine:id,slug')])
            ->orderByRaw($direction === 'desc' ? 'trend_30d DESC' : 'trend_30d ASC')
            ->limit(self::LIMIT * 4)
            ->get()
            ->unique('catalog_item_id')
            ->take(self::LIMIT)
            ->map(fn (MarketValue $mv) => $this->row($mv))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MarketValue $mv): array
    {
        $item = $mv->catalogItem;
        // trend_30d is a percent; back out the ~30-days-ago value to show the
        // dollar move alongside the percentage.
        $t = (float) $mv->trend_30d / 100.0;
        $prior = $t > -1 ? $mv->median / (1 + $t) : null;

        return [
            'name' => $item->display_name ?: $item->name,
            'number' => $item->number,
            'set' => $item->set?->name,
            'href' => $item->path(),
            'image' => $item->primary_image_path,
            'value' => $mv->median,
            'trend' => (float) $mv->trend_30d,
            'change' => $prior !== null ? (int) round($mv->median - $prior) : null,
        ];
    }

    /**
     * Product lines that actually have ranked movers, for the filter chips.
     *
     * @return array<int, array{slug: string, name: string}>
     */
    private function lines(): array
    {
        return ProductLine::query()
            ->whereHas('catalogItems', fn (Builder $q) => $q
                ->whereNotNull('primary_image_path')
                ->whereHas('marketValues', fn (Builder $q) => $q
                    ->whereNull('grading_company_id')
                    ->where('is_estimated', false)
                    ->where('median', '>=', self::MIN_MEDIAN_CENTS)
                    ->whereNotNull('trend_30d')
                    ->where('trend_30d', '!=', 0)))
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->map(fn (ProductLine $l) => ['slug' => $l->slug, 'name' => $l->name])
            ->all();
    }
}
