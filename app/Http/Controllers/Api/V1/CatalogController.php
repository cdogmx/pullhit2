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
