<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Http\Controllers\Controller;
use App\Models\CompAdjudication;
use App\Models\PriceHealthSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How far our prices sit from an outside source, and what the AI pass made of
 * the comps behind the worst of them.
 *
 * The measurement matters more than the findings. Every pricing bug found so far
 * was invisible from the inside — a slab counted as a raw card, a value that
 * outlived the comps it was built from, a matcher that ignored the printing.
 * Each was caught by comparing against PriceCharting, which nothing we compute
 * can move. The trend is here so that stays visible rather than living in a
 * console nobody reads.
 *
 * The findings are suggestions. A model read a title, a deterministic matcher
 * placed it, and the two disagreed with where the comp is filed — which is worth
 * a look, not an automatic deletion. Applying one removes a real sale, so it is
 * a person's decision.
 */
class PriceHealthController extends Controller
{
    public function index(Request $request): Response
    {
        $latest = PriceHealthSnapshot::latest('id')->first();

        $findings = CompAdjudication::query()
            ->where('status', CompAdjudication::OPEN)
            // product_line and slug as well, because path() needs all three to
            // build a card's /{brand}/{set}/{card} URL — without them every
            // finding would link nowhere.
            ->with([
                'catalogItem:id,name,number,slug,set_id,product_line_id',
                'catalogItem.set:id,name,slug',
                'catalogItem.productLine:id,slug',
                'readsAs:id,name,number,slug,set_id,product_line_id',
                'readsAs.set:id,name,slug',
                'readsAs.productLine:id,slug',
            ])
            // Worst divergence first: the finding on a card that is 30x off is
            // worth more attention than one on a card that is 3x off.
            ->orderByDesc('ratio')
            ->paginate(50)
            ->through(fn (CompAdjudication $f) => [
                'id' => $f->id,
                'price' => $f->price,
                'title' => $f->title,
                // The listing itself. Checking the model's reading against the
                // real page is the only way to judge whether to trust the next
                // one, so it has to be one click away.
                'url' => $f->url,
                'ratio' => $f->ratio,
                // Null once another pass has pruned the comp. The finding is
                // then just history, and the UI says so rather than offering a
                // button that would do nothing.
                'stale' => $f->sale_observation_id === null,
                'filed_under' => $f->catalogItem ? [
                    'id' => $f->catalogItem->id,
                    'label' => $f->catalogItem->name.' #'.$f->catalogItem->number,
                    'set' => $f->catalogItem->set?->name,
                    'url' => $f->catalogItem->path() ?? '/catalog/'.$f->catalogItem->id,
                ] : null,
                'reads_as' => $f->readsAs ? [
                    'id' => $f->readsAs->id,
                    'label' => $f->readsAs->name.' #'.$f->readsAs->number,
                    'set' => $f->readsAs->set?->name,
                    'url' => $f->readsAs->path() ?? '/catalog/'.$f->readsAs->id,
                ] : null,
            ]);

        return Inertia::render('admin/price-health', [
            'latest' => $latest ? [
                'compared' => $latest->compared,
                'buckets' => $latest->buckets,
                'over_2x' => $latest->over_2x,
                'under_half' => $latest->under_half,
                'taken_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            // Oldest first, so the chart reads left to right.
            'trend' => PriceHealthSnapshot::latest('id')->take(30)->get()
                ->reverse()->values()
                ->map(fn (PriceHealthSnapshot $s) => [
                    'taken_at' => $s->created_at?->toDateString(),
                    'compared' => $s->compared,
                    'over_2x' => $s->over_2x,
                    'under_half' => $s->under_half,
                ]),
            'findings' => $findings,
            'open' => CompAdjudication::where('status', CompAdjudication::OPEN)->count(),
        ]);
    }

    /**
     * Remove the comp this finding is about.
     *
     * Deletes a real sale, so the card is recomputed immediately — leaving the
     * old value behind is the mistake that put 1,066 cards on prices built from
     * comps that no longer existed.
     */
    public function apply(CompAdjudication $compAdjudication, RecomputeCatalogItem $recompute): RedirectResponse
    {
        if ($compAdjudication->status !== CompAdjudication::OPEN) {
            return back()->withErrors(['finding' => 'That finding has already been decided.']);
        }

        $card = $compAdjudication->catalogItem;
        $compAdjudication->saleObservation?->delete();

        $compAdjudication->forceFill([
            'status' => CompAdjudication::APPLIED,
            'reviewed_by' => request()->user()->id,
            'reviewed_at' => now(),
        ])->save();

        if ($card) {
            ($recompute)($card);
        }

        return back()->with('success', 'Comp removed and the card repriced.');
    }

    /**
     * The model was wrong. Say so and keep the comp.
     *
     * Worth recording rather than just closing: a run of dismissals on the same
     * shape is evidence the thresholds are too loose, and that is the kind of
     * thing nobody notices without a count.
     */
    public function dismiss(CompAdjudication $compAdjudication): RedirectResponse
    {
        $compAdjudication->forceFill([
            'status' => CompAdjudication::DISMISSED,
            'reviewed_by' => request()->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return back()->with('success', 'Dismissed. The comp stays.');
    }
}
