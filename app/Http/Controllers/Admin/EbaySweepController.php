<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\EbaySweepMiss;
use App\Models\EbaySweepOverride;
use App\Models\GradingCompany;
use App\Models\SaleObservation;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $misses = EbaySweepMiss::with('bestCatalogItem:id,name,number,primary_image_path')
            ->when(in_array($reason, self::REASONS, true), fn (Builder $q) => $q->where('reason', $reason))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        $applied = SaleObservation::with('catalogItem:id,name,number,primary_image_path')
            ->where('raw->source', 'ebay_sweep')
            ->latest('observed_at')
            ->limit(25)
            ->get();

        return Inertia::render('admin/ebay-sweep', [
            'misses' => collect($misses->items())->map(fn (EbaySweepMiss $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'image' => $m->image_url,
                'url' => "https://www.ebay.com/itm/{$m->source_listing_id}",
                'reason' => $m->reason,
                'number' => $m->parsed_number,
                'price' => $m->price,
                'best' => $m->bestCatalogItem
                    ? "#{$m->bestCatalogItem->number} {$m->bestCatalogItem->name}"
                    : null,
                'best_id' => $m->best_catalog_item_id,
                'best_image' => $m->bestCatalogItem?->primary_image_path,
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
                'card_image' => $o->catalogItem?->primary_image_path,
                'grade' => $o->grade_label,
                'price' => $o->price,
                'title' => $o->raw['title'] ?? null,
                'image' => $o->raw['image'] ?? null,
                'url' => $o->raw['url'] ?? ($o->source_listing_id ? "https://www.ebay.com/itm/{$o->source_listing_id}" : null),
                'search' => $o->raw['sweep'] ?? null,
                'observed_at' => $o->observed_at?->toIso8601String(),
            ]),
            'appliedTotal' => SaleObservation::where('raw->source', 'ebay_sweep')->count(),
        ]);
    }

    /**
     * Reject an applied sweep sale: drop the observation, recompute the card,
     * and remember the listing so future sweeps never re-apply it.
     */
    public function reject(SaleObservation $saleObservation, RecomputeCatalogItem $recompute): RedirectResponse
    {
        $card = $saleObservation->catalogItem;
        $listingId = $saleObservation->source_listing_id;
        $title = $saleObservation->raw['title'] ?? null;

        $saleObservation->delete();

        if ($card) {
            ($recompute)($card);
        }

        if ($listingId) {
            EbaySweepOverride::updateOrCreate(
                ['source_listing_id' => $listingId],
                ['action' => EbaySweepOverride::REJECT, 'catalog_item_id' => null, 'title' => $title, 'created_by' => auth()->id()],
            );
        }

        return back()->with('success', 'Sale rejected and suppressed.');
    }

    /**
     * Reassign an applied sweep sale to the correct card: move the observation,
     * recompute both cards, and remember the listing → card so future sweeps
     * apply it there.
     */
    public function reassign(Request $request, SaleObservation $saleObservation, RecomputeCatalogItem $recompute): RedirectResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => ['required', 'integer', 'exists:catalog_items,id'],
        ]);

        $newId = (int) $validated['catalog_item_id'];
        $oldCard = $saleObservation->catalogItem;
        $listingId = $saleObservation->source_listing_id;

        if ($newId !== $saleObservation->catalog_item_id) {
            $new = CatalogItem::findOrFail($newId);

            // Recreate on the correct card (dedup by listing id), then drop the old.
            $new->saleObservations()->updateOrCreate(
                ['source_listing_id' => $listingId, 'venue' => $saleObservation->venue],
                [
                    'condition' => $saleObservation->condition?->value,
                    'grading_company_id' => $saleObservation->grading_company_id,
                    'grade' => $saleObservation->grade,
                    'grade_label' => $saleObservation->grade_label,
                    'price' => $saleObservation->price,
                    'currency' => $saleObservation->currency,
                    'observed_at' => $saleObservation->observed_at,
                    'seller' => $saleObservation->seller,
                    'is_outlier' => false,
                    'is_synthetic' => false,
                    'raw' => $saleObservation->raw,
                ],
            );

            $saleObservation->delete();

            if ($oldCard) {
                ($recompute)($oldCard);
            }
            ($recompute)($new);
        }

        if ($listingId) {
            EbaySweepOverride::updateOrCreate(
                ['source_listing_id' => $listingId],
                ['action' => EbaySweepOverride::REASSIGN, 'catalog_item_id' => $newId, 'title' => $saleObservation->raw['title'] ?? null, 'created_by' => auth()->id()],
            );
        }

        return back()->with('success', 'Sale reassigned to the correct card.');
    }

    /**
     * Reject an unmatched listing: drop the miss and remember the listing so
     * future sweeps never re-log or apply it (junk, lots, non-catalog cards).
     */
    public function rejectMiss(EbaySweepMiss $ebaySweepMiss): RedirectResponse
    {
        if ($ebaySweepMiss->source_listing_id) {
            EbaySweepOverride::updateOrCreate(
                ['source_listing_id' => $ebaySweepMiss->source_listing_id],
                ['action' => EbaySweepOverride::REJECT, 'catalog_item_id' => null, 'title' => $ebaySweepMiss->title, 'created_by' => auth()->id()],
            );
        }

        $ebaySweepMiss->delete();

        return back()->with('success', 'Listing rejected and suppressed.');
    }

    /**
     * Manually assign an unmatched / low-confidence listing to a card: ingest it
     * as a real sale on the chosen card, remember the listing → card so future
     * sweeps follow, and clear the miss.
     */
    public function assign(Request $request, EbaySweepMiss $ebaySweepMiss, SoldCompClassifier $classifier, RecomputeCatalogItem $recompute): RedirectResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => ['required', 'integer', 'exists:catalog_items,id'],
        ]);

        abort_if((int) $ebaySweepMiss->price <= 0, 422, 'This listing has no usable price.');

        $card = CatalogItem::findOrFail((int) $validated['catalog_item_id']);
        $listingId = $ebaySweepMiss->source_listing_id;

        // Read the priced state (graded/raw) from the title; the admin asserts
        // the card, so the resolver/name gates are skipped.
        $candidate = new SoldCandidate(
            $ebaySweepMiss->title,
            (int) $ebaySweepMiss->price,
            $ebaySweepMiss->sold_at ? CarbonImmutable::parse($ebaySweepMiss->sold_at) : null,
            $listingId,
            "https://www.ebay.com/itm/{$listingId}",
            imageUrl: $ebaySweepMiss->image_url,
        );
        $comp = $classifier->pricedState($candidate, GradingCompany::pluck('id', 'slug')->all());

        $card->saleObservations()->updateOrCreate(
            ['source_listing_id' => $comp->sourceListingId, 'venue' => 'ebay'],
            [
                'condition' => $comp->condition,
                'grading_company_id' => $comp->gradingCompanyId,
                'grade' => $comp->grade,
                'grade_label' => $comp->gradeLabel,
                'price' => $comp->priceCents,
                'currency' => 'USD',
                'observed_at' => $comp->soldAt ?? Carbon::now(),
                'seller' => $comp->seller,
                'is_outlier' => false,
                'is_synthetic' => false,
                'raw' => ['title' => $comp->title, 'url' => $comp->url, 'image' => $comp->imageUrl, 'source' => 'ebay_sweep', 'sweep' => 'manual'],
            ],
        );

        EbaySweepOverride::updateOrCreate(
            ['source_listing_id' => $listingId],
            ['action' => EbaySweepOverride::REASSIGN, 'catalog_item_id' => $card->id, 'title' => $ebaySweepMiss->title, 'created_by' => auth()->id()],
        );

        $ebaySweepMiss->delete();
        ($recompute)($card);

        return back()->with('success', "Assigned to #{$card->number} {$card->name}.");
    }
}
