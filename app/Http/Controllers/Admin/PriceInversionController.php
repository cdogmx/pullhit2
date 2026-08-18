<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Support\Catalog\LikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Price inversions (admin). Cards whose graded value sits BELOW their own raw
 * value — a slab at 8 or better should not be worth less than the loose card.
 *
 * Where the grading-gap finder looks for opportunities, this looks for mistakes:
 * an inversion nearly always means one side of the pair is wrong. Usually the
 * raw side has swallowed a comp for a different card, a graded sale, or a lot —
 * a "raw" One Piece Luffy at $77,600 off a single sale is not a market, it is a
 * bad comp. Occasionally the graded side is the thin one instead.
 *
 * Read-only, and deliberately shows the evidence rather than just the verdict:
 * sale counts and confidence on both sides, so a real anomaly can be told from
 * two noisy numbers. Estimated (synthetic) values are excluded by default —
 * a seeded placeholder inverting against a real value says nothing.
 */
class PriceInversionController extends Controller
{
    /** Sort key => the expression to order by. */
    private const SORTS = [
        'gap' => 'gap',
        'ratio' => 'ratio',
        'raw' => 'nm.median',
        'graded' => 'g.median',
        'grade' => 'g.grade',
        'name' => 'catalog_items.name',
        'set' => 'sets.name',
        'sales' => 'g.n_sales',
    ];

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'brand' => trim((string) $request->query('brand', '')),
            'min_grade' => max(1.0, (float) $request->query('min_grade', 8)),
            'min_sales' => max(1, (int) $request->query('min_sales', 1)),
            'min_gap' => max(0, (int) round((float) $request->query('min_gap', 0) * 100)),
            'sort' => array_key_exists((string) $request->query('sort'), self::SORTS)
                ? (string) $request->query('sort')
                : 'gap',
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
        ];

        $paginator = CatalogItem::query()
            ->join('market_values as nm', fn ($j) => $j
                ->on('nm.catalog_item_id', 'catalog_items.id')
                ->where('nm.state_key', 'NM'))
            ->join('market_values as g', fn ($j) => $j
                ->on('g.catalog_item_id', 'catalog_items.id')
                ->whereNotNull('g.grading_company_id'))
            ->join('grading_companies as gc', 'gc.id', 'g.grading_company_id')
            ->leftJoin('sets', 'sets.id', 'catalog_items.set_id')
            ->where('g.grade', '>=', $filters['min_grade'])
            // The inversion itself. Written this way round so the subtraction
            // below can never underflow the UNSIGNED median column.
            ->whereColumn('g.median', '<', 'nm.median')
            ->where('nm.is_estimated', false)
            ->where('g.is_estimated', false)
            ->where('nm.n_sales', '>=', $filters['min_sales'])
            ->where('g.n_sales', '>=', $filters['min_sales'])
            ->when($filters['min_gap'] > 0, fn (Builder $q) => $q
                ->whereRaw('nm.median >= g.median + ?', [$filters['min_gap']]))
            ->when($filters['brand'] !== '', fn (Builder $q) => $q
                ->whereHas('productLine', fn (Builder $p) => $p->where('slug', $filters['brand'])))
            ->when($filters['q'] !== '', fn (Builder $q) => $this->applySearch($q, $filters['q']))
            ->with(['set:id,name,slug', 'productLine:id,name,slug'])
            ->select([
                'catalog_items.*',
                'nm.median as nm_median',
                'nm.n_sales as nm_n',
                'nm.confidence as nm_conf',
                'g.median as graded_median',
                'g.n_sales as graded_n',
                'g.confidence as graded_conf',
                'g.state_key as graded_state',
                'g.grade as graded_grade',
                'gc.name as grader',
            ])
            ->selectRaw('(nm.median - g.median) as gap')
            ->selectRaw('(g.median * 1.0 / NULLIF(nm.median, 0)) as ratio')
            ->orderByRaw(self::SORTS[$filters['sort']].' '.$filters['direction'])
            // A stable tiebreak so pagination can't repeat or skip a row.
            ->orderBy('catalog_items.id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/price-inversions', [
            'rows' => collect($paginator->items())->map($this->row(...))->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                ...$filters,
                'min_gap' => $filters['min_gap'] / 100,
            ],
            'options' => [
                'brands' => ProductLine::orderBy('name')->get(['slug', 'name'])
                    ->map(fn (ProductLine $p) => ['value' => $p->slug, 'label' => $p->name]),
            ],
        ]);
    }

    /**
     * Search the card's own name and number, and its set's name — the three
     * things an admin has in hand when chasing a specific bad value.
     *
     * @param  Builder<CatalogItem>  $query
     * @return Builder<CatalogItem>
     */
    protected function applySearch(Builder $query, string $q): Builder
    {
        $term = LikeTerm::clean($q);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $w) => $w
            ->where('catalog_items.name', 'like', "%{$term}%")
            ->orWhere('catalog_items.number', 'like', "%{$term}%")
            ->orWhere('sets.name', 'like', "%{$term}%"));
    }

    /** @return array<string, mixed> */
    protected function row(CatalogItem $item): array
    {
        $raw = (int) $item->getAttribute('nm_median');
        $graded = (int) $item->getAttribute('graded_median');

        return [
            'id' => $item->id,
            'name' => $item->display_name,
            'number' => $item->number,
            'set' => $item->set?->name,
            'brand' => $item->productLine?->name,
            'image_url' => $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null),
            'url' => $item->path() ?? "/catalog/{$item->id}",

            'grader' => $item->getAttribute('grader'),
            'grade' => (float) $item->getAttribute('graded_grade'),
            'state' => $item->getAttribute('graded_state'),

            'graded' => $graded,
            'graded_n' => (int) $item->getAttribute('graded_n'),
            'graded_confidence' => round((float) $item->getAttribute('graded_conf'), 2),

            'raw' => $raw,
            'raw_n' => (int) $item->getAttribute('nm_n'),
            'raw_confidence' => round((float) $item->getAttribute('nm_conf'), 2),

            // How wrong it looks: the shortfall, and the graded value as a
            // fraction of raw (0.10 = the slab reads a tenth of the loose card).
            'gap' => $raw - $graded,
            'ratio' => $raw > 0 ? round($graded / $raw, 3) : null,
        ];
    }
}
