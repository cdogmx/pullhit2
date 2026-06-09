<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Collection\AddToCollection;
use App\Actions\Collection\BuildPortfolio;
use App\Actions\Collection\RemoveFromCollection;
use App\Actions\Collection\UpdateCollectionItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\StoreCollectionItemRequest;
use App\Http\Resources\CollectionItemResource;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Collection + portfolio for API clients (native app). Same Collection Actions
 * as the web controller (§2); auth via Sanctum token.
 */
class CollectionController extends Controller
{
    public function index(Request $request, BuildPortfolio $build): JsonResponse
    {
        $portfolio = $build($request->user());

        return response()->json([
            'holdings' => CollectionItemResource::collection($portfolio['items']),
            'summary' => $portfolio['summary'],
            'allocation' => $portfolio['allocation'],
            'gainers' => $portfolio['gainers'],
            'decliners' => $portfolio['decliners'],
        ]);
    }

    public function store(StoreCollectionItemRequest $request, AddToCollection $add): JsonResponse
    {
        $data = $request->validated();
        $item = CatalogItem::findOrFail($data['catalog_item_id']);

        $collectionItem = $add($request->user(), $item, $data)
            ->load(['catalogItem.set', 'catalogItem.marketValues', 'gradingCompany', 'acquisitionLots']);

        return (new CollectionItemResource($collectionItem))->response()->setStatusCode(201);
    }

    public function update(Request $request, CollectionItem $collectionItem, UpdateCollectionItem $update): CollectionItemResource
    {
        $this->authorize('update', $collectionItem);

        $update($collectionItem, $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_for_sale' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]));

        return new CollectionItemResource(
            $collectionItem->load(['catalogItem.set', 'catalogItem.marketValues', 'gradingCompany', 'acquisitionLots'])
        );
    }

    public function destroy(CollectionItem $collectionItem, RemoveFromCollection $remove): JsonResponse
    {
        $this->authorize('delete', $collectionItem);

        $remove($collectionItem);

        return response()->json(status: 204);
    }
}
