<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\SearchCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchCatalogRequest;
use App\Http\Resources\CatalogItemResource;
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
}
