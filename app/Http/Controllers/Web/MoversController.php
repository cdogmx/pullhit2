<?php

namespace App\Http\Controllers\Web;

use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Biggest movers — the cards whose real (non-estimated) ungraded value moved most
 * over a selectable window (daily / 7d / 30d / 90d), split into gainers and
 * losers. Computed from the daily value_snapshots series (today's value vs the
 * value ~W days ago), so a card only appears if it was actually re-valued
 * recently (freshness gate) AND its value moved over the window. A price floor +
 * a minimum comp count keep penny-card noise out. Filterable by product line
 * (?line=slug), set (?set=slug), item type (?type=single|sealed), and window
 * (?window=daily|7d|30d|90d).
 */
class MoversController extends Controller
{
    /** Only rank cards worth at least this (cents) — filters out noisy penny swings. */
    private const MIN_MEDIAN_CENTS = 300;

    /** Require at least this many sold comps behind the value + trend. */
    private const MIN_SALES = 5;

    /**
     * Reject implausible swings so the page stays credible — bounds are
     * asymmetric: a card can genuinely multiply, but a drop past ~60% is almost
     * always a bad prior (and shows a change bigger than the value).
     */
    private const GAIN_CAP = 300.0;

    private const LOSS_FLOOR = -60.0;

    private const LIMIT = 24;

    /** Window slug → days back. Daily is the default. */
    private const WINDOWS = ['daily' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public function __invoke(Request $request): Response
    {
        $line = $request->string('line')->toString() ?: null;
        $lineId = $line ? ProductLine::where('slug', $line)->value('id') : null;
        $line = $lineId ? $line : null;

        // A set filter is only meaningful within its line; scope + validate it.
        $setSlug = $request->string('set')->toString() ?: null;
        $set = $setSlug
            ? Set::where('slug', $setSlug)
                ->when($lineId, fn (Builder $q) => $q->where('product_line_id', $lineId))
                ->first(['id', 'product_line_id'])
            : null;
        // A set implies its line (so a shared set link still filters correctly).
        if ($set) {
            $lineId = $set->product_line_id;
            $line = ProductLine::whereKey($lineId)->value('slug');
        }

        $type = $this->normalizeType($request->string('type')->toString());
        $window = $this->normalizeWindow($request->string('window')->toString());
        $windowDays = self::WINDOWS[$window];

        $filters = ['lineId' => $lineId, 'setId' => $set?->id, 'type' => $type];

        // One pass over the snapshot series, then split into gainers/losers.
        $moves = $this->computeMoves($filters, $windowDays);

        return Inertia::render('movers', [
            'gainers' => $this->present($moves->where('trend', '>=', 0.01)->sortByDesc('trend')),
            'losers' => $this->present($moves->where('trend', '<=', -0.01)->sortBy('trend')),
            'lines' => $this->lines(),
            'sets' => $lineId ? $this->sets($lineId) : [],
            'line' => $line,
            'set' => $set ? $setSlug : null,
            'type' => $type?->value,
            'window' => $window,
            'meta' => [
                'title' => 'Biggest movers — '.self::label($window).' price gainers & losers | CardFoo',
                'description' => 'The cards moving most on real sold-price data: the biggest '.self::label($window).' gainers and losers, with current value and change.',
            ],
        ]);
    }

    /** Only 'single' and 'sealed' are offered as filters; anything else = all. */
    private function normalizeType(string $type): ?ItemType
    {
        return match ($type) {
            'single' => ItemType::Single,
            'sealed' => ItemType::Sealed,
            default => null,
        };
    }

    /** Default to daily when the requested window is unknown/absent. */
    private function normalizeWindow(string $window): string
    {
        return isset(self::WINDOWS[$window]) ? $window : 'daily';
    }

    private static function label(string $window): string
    {
        return match ($window) {
            'daily' => 'daily',
            '7d' => '7-day',
            '30d' => '30-day',
            '90d' => '90-day',
            default => $window,
        };
    }

    /**
     * Every card's move over the window: its latest (fresh, real) snapshot value
     * vs the snapshot ~W days before it. Freshness = the latest snapshot is within
     * the last few days of the most recent snapshot day, so a card that stopped
     * being re-valued drops off; a flat value yields a 0% move and is filtered out.
     *
     * @param  array{lineId: int|null, setId: int|null, type: ItemType|null}  $filters
     * @return Collection<int, array{id: int, value: int, trend: float, change: int}>
     */
    private function computeMoves(array $filters, int $windowDays): Collection
    {
        $refDate = DB::table('value_snapshots')->max('captured_on');
        if ($refDate === null) {
            return collect();
        }

        // The moves only change when a new day's snapshots land, so cache them
        // keyed by the latest snapshot day (+ window + filters) — the ~6k-row
        // correlated baseline lookup then runs once per day, not per request. The
        // refDate in the key self-invalidates when the daily job advances it.
        $key = sprintf(
            'movers:%s:%d:%s:%s:%s',
            $refDate, $windowDays,
            $filters['lineId'] ?? '', $filters['setId'] ?? '', $filters['type']?->value ?? '',
        );

        return Cache::remember($key, now()->addHours(12), fn () => $this->queryMoves($filters, $windowDays, $refDate));
    }

    /**
     * The uncached move computation (see {@see computeMoves()} for the caching).
     *
     * @param  array{lineId: int|null, setId: int|null, type: ItemType|null}  $filters
     * @return Collection<int, array{id: int, value: int, trend: float, change: int}>
     */
    private function queryMoves(array $filters, int $windowDays, string $refDate): Collection
    {
        // "Fresh" = re-valued in the last day (daily) or few days (longer windows).
        $freshFrom = Carbon::parse($refDate)->subDays(min($windowDays, 3))->toDateString();
        // The baseline is the latest snapshot on-or-before this cutoff (computed
        // here so the SQL stays driver-agnostic — no DATE_SUB).
        $cutoff = Carbon::parse($refDate)->subDays($windowDays)->toDateString();

        // The most recent fresh snapshot per (card, state). DATE() so the compare
        // is date-only across drivers (SQLite stores a time; MySQL is a pure DATE).
        $latest = DB::table('value_snapshots')
            ->select('catalog_item_id', 'state_key', DB::raw('MAX(captured_on) as latest_on'))
            ->where('is_estimated', false)
            ->whereRaw('DATE(captured_on) >= ?', [$freshFrom])
            ->groupBy('catalog_item_id', 'state_key');

        $rows = DB::table('value_snapshots as l')
            ->joinSub($latest, 'lm', fn ($j) => $j
                ->on('lm.catalog_item_id', '=', 'l.catalog_item_id')
                ->on('lm.state_key', '=', 'l.state_key')
                ->on('lm.latest_on', '=', 'l.captured_on'))
            ->join('catalog_items as ci', 'ci.id', '=', 'l.catalog_item_id')
            ->where('l.is_estimated', false)
            ->where('l.median_cents', '>=', self::MIN_MEDIAN_CENTS)
            ->where('l.n_sales', '>=', self::MIN_SALES)
            ->whereNotNull('ci.primary_image_path')
            ->when($filters['lineId'], fn (QueryBuilder $q, $id) => $q->where('ci.product_line_id', $id))
            ->when($filters['setId'], fn (QueryBuilder $q, $id) => $q->where('ci.set_id', $id))
            ->when($filters['type'], fn (QueryBuilder $q, ItemType $t) => $q->where('ci.item_type', $t->value))
            // The closest real snapshot on-or-before the window cutoff is the
            // baseline — handles gaps in a card's daily series.
            ->selectRaw(
                'l.catalog_item_id, l.median_cents as latest_median, '.
                '(SELECT b.median_cents FROM value_snapshots b '.
                'WHERE b.catalog_item_id = l.catalog_item_id AND b.state_key = l.state_key '.
                'AND b.is_estimated = 0 AND DATE(b.captured_on) <= ? '.
                'ORDER BY b.captured_on DESC LIMIT 1) as baseline_median',
                [$cutoff],
            )
            ->get();

        return $rows
            ->map(function (object $r): ?array {
                $base = (int) ($r->baseline_median ?? 0);
                if ($base <= 0) {
                    return null;
                }

                $trend = round(((int) $r->latest_median / $base - 1) * 100, 2);

                // Keep only credible, non-trivial moves.
                if ($trend > self::GAIN_CAP || $trend < self::LOSS_FLOOR) {
                    return null;
                }

                return [
                    'id' => (int) $r->catalog_item_id,
                    'value' => (int) $r->latest_median,
                    'trend' => $trend,
                    'change' => (int) $r->latest_median - $base,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Turn the top-N ranked moves into display rows (hydrating card names/paths).
     *
     * @param  Collection<int, array{id: int, value: int, trend: float, change: int}>  $moves
     * @return array<int, array<string, mixed>>
     */
    private function present(Collection $moves): array
    {
        $top = $moves->take(self::LIMIT)->values();

        $items = CatalogItem::query()
            ->whereIn('id', $top->pluck('id'))
            ->with('set:id,name,slug', 'productLine:id,slug')
            ->get()
            ->keyBy('id');

        return $top
            ->map(function (array $m) use ($items) {
                $item = $items->get($m['id']);
                if ($item === null) {
                    return null;
                }

                return [
                    'name' => $item->display_name ?: $item->name,
                    'number' => $item->number,
                    'set' => $item->set?->name,
                    'href' => $item->path(),
                    'image' => $item->primary_image_path,
                    'value' => $m['value'],
                    'trend' => $m['trend'],
                    'change' => $m['change'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Product lines that actually have ranked movers, for the brand filter.
     *
     * @return array<int, array{slug: string, name: string}>
     */
    private function lines(): array
    {
        return ProductLine::query()
            ->whereHas('catalogItems', fn (Builder $q) => $this->hasRankableValue($q))
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->map(fn (ProductLine $l) => ['slug' => $l->slug, 'name' => $l->name])
            ->all();
    }

    /**
     * Sets within a line that have ranked movers, for the set filter.
     *
     * @return array<int, array{slug: string, name: string}>
     */
    private function sets(int $lineId): array
    {
        return Set::query()
            ->where('product_line_id', $lineId)
            ->whereHas('catalogItems', fn (Builder $q) => $this->hasRankableValue($q))
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->map(fn (Set $s) => ['slug' => $s->slug, 'name' => $s->name])
            ->all();
    }

    /** A catalog-item scope: has a real, non-trivial ungraded value worth ranking. */
    private function hasRankableValue(Builder $q): Builder
    {
        return $q
            ->whereNotNull('primary_image_path')
            ->whereHas('marketValues', fn (Builder $q) => $q
                ->whereNull('grading_company_id')
                ->where('is_estimated', false)
                ->where('median', '>=', self::MIN_MEDIAN_CENTS));
    }
}
