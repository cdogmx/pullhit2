<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EbaySweepMiss;
use App\Models\SaleObservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only window into the broad eBay sold-comp sweep: what it applied to card
 * values vs. the listings it couldn't confidently place (by reason), so match
 * quality can be watched and the resolver tuned.
 */
class EbaySweepController extends Controller
{
    private const REASONS = ['no_number', 'unmatched', 'ambiguous', 'low_score', 'classify_rejected'];

    public function index(Request $request): Response
    {
        $reason = (string) $request->query('reason', 'all');

        $misses = EbaySweepMiss::with('bestCatalogItem:id,name,number')
            ->when(in_array($reason, self::REASONS, true), fn (Builder $q) => $q->where('reason', $reason))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        $applied = SaleObservation::with('catalogItem:id,name,number')
            ->where('raw->source', 'ebay_sweep')
            ->latest('observed_at')
            ->limit(25)
            ->get();

        return Inertia::render('admin/ebay-sweep', [
            'misses' => collect($misses->items())->map(fn (EbaySweepMiss $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'reason' => $m->reason,
                'number' => $m->parsed_number,
                'price' => $m->price,
                'best' => $m->bestCatalogItem
                    ? "#{$m->bestCatalogItem->number} {$m->bestCatalogItem->name}"
                    : null,
                'best_id' => $m->best_catalog_item_id,
                'score' => $m->best_score,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'pagination' => [
                'page' => $misses->currentPage(),
                'last_page' => $misses->lastPage(),
                'total' => $misses->total(),
            ],
            'counts' => EbaySweepMiss::select('reason', DB::raw('count(*) as c'))
                ->groupBy('reason')->pluck('c', 'reason'),
            'reason' => $reason,
            'applied' => $applied->map(fn (SaleObservation $o) => [
                'id' => $o->id,
                'card' => $o->catalogItem ? "#{$o->catalogItem->number} {$o->catalogItem->name}" : '—',
                'card_id' => $o->catalog_item_id,
                'grade' => $o->grade_label,
                'price' => $o->price,
                'title' => $o->raw['title'] ?? null,
                'search' => $o->raw['sweep'] ?? null,
                'observed_at' => $o->observed_at?->toIso8601String(),
            ]),
            'appliedTotal' => SaleObservation::where('raw->source', 'ebay_sweep')->count(),
        ]);
    }
}
