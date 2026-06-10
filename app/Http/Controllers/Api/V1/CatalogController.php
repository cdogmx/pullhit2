<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\GetPricedStateBreakdown;
use App\Actions\Catalog\SearchCatalog;
use App\Actions\Catalog\ShowCatalogItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchCatalogRequest;
use App\Http\Resources\CatalogItemResource;
use App\Http\Resources\MarketValueResource;
use App\Http\Resources\SaleObservationResource;
use App\Models\CatalogItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public catalog browse/search (JSON). Same Actions as the web controller so the
 * future native app gets identical behaviour.
 */
class CatalogController extends Controller
{
    public function index(
        SearchCatalogRequest $request,
        SearchCatalog $search,
        CatalogFilterOptions $options,
    ): AnonymousResourceCollection {
        $filters = $request->filters();

        return CatalogItemResource::collection($search($filters))
            ->additional([
                'options' => $options($filters),
                'filters' => $filters,
            ]);
    }

    public function show(CatalogItem $catalogItem, ShowCatalogItem $show): CatalogItemResource
    {
        return new CatalogItemResource($show($catalogItem));
    }

    /**
     * Current market values + refresh status for a card — polled by the detail
     * page to live-swap values once a background eBay refresh completes. Read-only
     * (no popularity bump, no dispatch).
     */
    public function values(CatalogItem $catalogItem): JsonResponse
    {
        $catalogItem->load('marketValues.gradingCompany');

        $hours = (int) config('valuation.ebay.view_refresh_hours', 12);
        $refreshing = (bool) config('valuation.ebay.enabled') && (
            $catalogItem->ebay_refreshed_at === null
            || $catalogItem->ebay_refreshed_at->lt(now()->subHours($hours))
        );

        return response()->json([
            'market_values' => MarketValueResource::collection($catalogItem->marketValues),
            'refreshed_at' => $catalogItem->ebay_refreshed_at?->toIso8601String(),
            'refreshing' => $refreshing,
        ]);
    }

    /**
     * The comps/sources behind one priced state's value (price-breakdown drawer).
     */
    public function observations(Request $request, CatalogItem $catalogItem, GetPricedStateBreakdown $breakdown): JsonResponse
    {
        $stateKey = (string) $request->query('state_key', '');
        $result = $breakdown($catalogItem, $stateKey);

        if ($result === null) {
            return response()->json(['value' => null, 'observations' => [], 'sources' => []], 404);
        }

        return response()->json([
            'value' => new MarketValueResource($result['value']),
            'observations' => SaleObservationResource::collection($result['observations']),
            'sources' => $result['sources'],
        ]);
    }
}
