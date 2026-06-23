<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public landing page. Under the hero we surface the live catalog: trending
 * cards, freshly-repriced cards, the biggest 30-day movers, brand shortcuts,
 * newest sets, plus how the points/giveaways work. Cached briefly — this is the
 * highest-traffic page and the data shifts slowly.
 */
class HomeController extends Controller
{
    public function index(): Response
    {
        $sections = Cache::remember('home:sections:v2', Carbon::now()->addMinutes(10), fn () => [
            'brands' => $this->brands(),
            'trending' => $this->trending(),
            'movers' => $this->movers(),
            'recent' => $this->recent(),
            'popularSets' => $this->popularSets(),
        ]);

        return Inertia::render('welcome', [
            ...$sections,
            'community' => $this->community(),
        ]);
    }

    /** Product lines as quick-nav chips, busiest first. */
    private function brands(): array
    {
        return ProductLine::query()
            ->select('id', 'slug', 'name', 'logo_path')
            ->selectSub(
                CatalogItem::selectRaw('count(*)')->whereColumn('product_line_id', 'product_lines.id'),
                'item_count',
            )
            ->orderByDesc('item_count')
            ->get()
            ->filter(fn (ProductLine $b) => $b->item_count > 0)
            ->map(fn (ProductLine $b) => [
                'slug' => $b->slug,
                'name' => $b->name,
                'logo' => $b->logo_path,
                'count' => (int) $b->item_count,
                'href' => "/browse/{$b->slug}",
            ])
            ->values()
            ->all();
    }

    /** Most-viewed cards. */
    private function trending(): array
    {
        return CatalogItem::query()
            ->whereNotNull('primary_image_path')
            ->where('popularity', '>', 0)
            ->with(['set:id,name,slug', 'productLine:id,slug', 'defaultMarketValue'])
            ->orderByDesc('popularity')
            ->limit(14)
            ->get()
            ->map(fn (CatalogItem $i) => $this->tile($i))
            ->all();
    }

    /** Biggest 30-day moves on real (non-estimated) values. */
    private function movers(): array
    {
        return MarketValue::query()
            ->whereNull('grading_company_id')
            ->where('is_estimated', false)
            ->where('median', '>', 0)
            ->whereNotNull('trend_30d')
            ->where('trend_30d', '!=', 0)
            ->whereHas('catalogItem', fn (Builder $q) => $q->whereNotNull('primary_image_path'))
            ->with(['catalogItem' => fn ($q) => $q->with('set:id,name,slug', 'productLine:id,slug')])
            ->orderByRaw('ABS(trend_30d) DESC')
            ->limit(40)
            ->get()
            ->unique('catalog_item_id')
            ->take(10)
            ->map(fn (MarketValue $mv) => $this->tile($mv->catalogItem, $mv))
            ->values()
            ->all();
    }

    /** Freshly recomputed real prices — the live pulse (eBay sweeps + refreshes). */
    private function recent(): array
    {
        return MarketValue::query()
            ->where('is_estimated', false)
            ->where('median', '>', 0)
            ->whereHas('catalogItem', fn (Builder $q) => $q->whereNotNull('primary_image_path'))
            ->with(['catalogItem' => fn ($q) => $q->with('set:id,name,slug', 'productLine:id,slug')])
            ->orderByDesc('computed_at')
            ->limit(60)
            ->get()
            ->unique('catalog_item_id')
            ->take(14)
            ->map(function (MarketValue $mv) {
                $tile = $this->tile($mv->catalogItem, $mv);
                $tile['state'] = $mv->state_key;
                $tile['updated_at'] = $mv->computed_at?->toIso8601String();

                return $tile;
            })
            ->values()
            ->all();
    }

    /** Most popular sets — ranked by their cards' total views. */
    private function popularSets(): array
    {
        return Set::query()
            ->with('productLine:id,slug')
            ->addSelect(['item_count' => CatalogItem::selectRaw('count(*)')->whereColumn('set_id', 'sets.id')])
            ->addSelect(['views' => CatalogItem::selectRaw('coalesce(sum(popularity), 0)')->whereColumn('set_id', 'sets.id')])
            ->orderByDesc('views')
            ->limit(20)
            ->get()
            ->filter(fn (Set $s) => $s->item_count > 0)
            ->take(12)
            ->map(fn (Set $s) => [
                'name' => $s->name,
                'code' => $s->code,
                'logo' => $s->logo_path,
                'released' => $s->released_at?->toDateString(),
                'count' => (int) $s->item_count,
                'href' => $s->productLine ? "/browse/{$s->productLine->slug}/{$s->slug}" : null,
            ])
            ->values()
            ->all();
    }

    /** Points/levels config + giveaway framing for the explainer section. */
    private function community(): array
    {
        return [
            'points' => config('community.points'),
            'levels' => collect(config('community.levels'))
                ->map(fn (array $l) => ['name' => $l['name'], 'min' => $l['min']])
                ->all(),
            'month' => Carbon::now()->format('F'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tile(CatalogItem $item, ?MarketValue $value = null): array
    {
        $mv = $value ?? $item->defaultMarketValue;

        return [
            'name' => $item->display_name ?: $item->name,
            'number' => $item->number,
            'set' => $item->set?->name,
            'href' => $item->path(),
            'image' => $item->primary_image_path,
            'value' => $mv?->median,
            'estimated' => (bool) ($mv?->is_estimated ?? true),
            'trend' => $mv?->trend_30d !== null ? (float) $mv->trend_30d : null,
        ];
    }
}
