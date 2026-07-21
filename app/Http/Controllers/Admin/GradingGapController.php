<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Grading-arbitrage finder (admin). Surfaces cards worth materially more in a PSA
 * 10 slab than raw — the ones where buying a Near Mint copy, grading it, and
 * selling the 10 clears a profit after the grading fee.
 *
 * profit = PSA 10 value − (Near Mint price + grading fee)
 *
 * Only real (non-estimated) values on both sides count, with sale-count floors so
 * a thin graded market can't fake a gap. All read-only.
 */
class GradingGapController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'fee' => max(0, (int) round((float) $request->query('fee', 25) * 100)),
            'min_value' => max(0, (int) round((float) $request->query('min_value', 3) * 100)),
            'min_graded_sales' => max(1, (int) $request->query('min_graded_sales', 2)),
            'sort' => in_array($request->query('sort'), ['profit', 'multiple'], true)
                ? (string) $request->query('sort')
                : 'profit',
        ];

        $orderBy = $filters['sort'] === 'multiple'
            ? 'g.median * 1.0 / nm.median desc'
            : '(g.median - nm.median) desc';

        $paginator = CatalogItem::query()
            ->join('market_values as nm', fn ($j) => $j
                ->on('nm.catalog_item_id', 'catalog_items.id')
                ->where('nm.state_key', 'NM'))
            ->join('market_values as g', fn ($j) => $j
                ->on('g.catalog_item_id', 'catalog_items.id')
                ->where('g.state_key', 'psa-10'))
            ->where('nm.is_estimated', false)
            ->where('g.is_estimated', false)
            ->where('nm.median', '>=', $filters['min_value'])
            ->where('g.n_sales', '>=', $filters['min_graded_sales'])
            // Graded worth enough more to clear the grading fee — a real
            // opportunity. Written as addition (not g.median - nm.median) so the
            // subtraction can't underflow the UNSIGNED columns for NM > PSA-10 rows.
            ->whereRaw('g.median > nm.median + ?', [$filters['fee']])
            ->with('set:id,name,slug')
            ->select([
                'catalog_items.*',
                'nm.median as nm_median',
                'nm.n_sales as nm_n',
                'nm.confidence as nm_conf',
                'g.median as psa10_median',
                'g.n_sales as psa10_n',
                'g.confidence as psa10_conf',
            ])
            ->orderByRaw($orderBy)
            ->orderByDesc('g.median')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/grading-gaps', [
            'rows' => collect($paginator->items())->map(fn (CatalogItem $i) => $this->row($i, $filters['fee'])),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'fee' => $filters['fee'] / 100,
                'min_value' => $filters['min_value'] / 100,
                'min_graded_sales' => $filters['min_graded_sales'],
                'sort' => $filters['sort'],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function row(CatalogItem $item, int $feeCents): array
    {
        $nm = (int) $item->getAttribute('nm_median');
        $psa10 = (int) $item->getAttribute('psa10_median');

        return [
            'id' => $item->id,
            'name' => $item->display_name,
            'number' => $item->number,
            'set' => $item->set?->name,
            'image_url' => $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null),
            'url' => "/catalog/{$item->id}",
            'nm' => $nm,
            'nm_n' => (int) $item->getAttribute('nm_n'),
            'nm_confidence' => round((float) $item->getAttribute('nm_conf'), 2),
            'psa10' => $psa10,
            'psa10_n' => (int) $item->getAttribute('psa10_n'),
            'psa10_confidence' => round((float) $item->getAttribute('psa10_conf'), 2),
            // Headline metrics: the raw gap, the multiple, and the profit net of fee.
            'delta' => $psa10 - $nm,
            'multiple' => $nm > 0 ? round($psa10 / $nm, 1) : null,
            'profit' => $psa10 - $nm - $feeCents,
        ];
    }
}
