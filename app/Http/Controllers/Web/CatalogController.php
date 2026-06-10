<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\SearchCatalog;
use App\Actions\Catalog\ShowCatalogItem;
use App\Actions\Valuation\MaybeRefreshEbay;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchCatalogRequest;
use App\Http\Resources\CatalogItemResource;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public catalog browse/search (Inertia). Thin — delegates to the same Actions
 * the API uses (§2).
 */
class CatalogController extends Controller
{
    public function index(
        SearchCatalogRequest $request,
        SearchCatalog $search,
        CatalogFilterOptions $options,
    ): Response {
        $filters = $request->filters();
        $paginator = $search($filters);

        return Inertia::render('catalog/browse', [
            // Merge so each scrolled-in page appends to the list (infinite scroll);
            // a filter change resets it via the request's reset header.
            'items' => Inertia::merge(
                fn () => CatalogItemResource::collection($paginator->items())->resolve(),
            ),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'options' => $options($filters),
            'filters' => $filters,
        ]);
    }

    public function show(CatalogItem $catalogItem, ShowCatalogItem $show, MaybeRefreshEbay $maybeRefresh): Response
    {
        // Record the view (popularity drives refresh cadence) and, if the eBay
        // data is stale for this item's tier, queue a background refresh. The
        // page renders the current cached value immediately.
        $catalogItem->forceFill([
            'popularity' => $catalogItem->popularity + 1,
            'last_viewed_at' => Carbon::now(),
        ])->save();

        // When stale (>12h), this queues a background refresh and reports true so
        // the page shows an "updating" indicator and polls for the new values.
        $refreshing = $maybeRefresh($catalogItem);

        // The resource wraps under `data` (consistent with the API + the browse
        // collection); the page reads props.item.data.
        return Inertia::render('catalog/show', [
            'item' => new CatalogItemResource($show($catalogItem)),
            'refreshing' => $refreshing,
            // Options for the "add to collection" graded picker.
            'gradingCompanies' => GradingCompany::orderBy('name')
                ->get(['id', 'slug', 'name', 'scale_max', 'supports_half_grades']),
        ]);
    }
}
