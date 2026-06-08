<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\SearchCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchCatalogRequest;
use App\Http\Resources\CatalogItemResource;
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

        return Inertia::render('catalog/browse', [
            'items' => CatalogItemResource::collection($search($filters)),
            'options' => $options($filters),
            'filters' => $filters,
        ]);
    }
}
