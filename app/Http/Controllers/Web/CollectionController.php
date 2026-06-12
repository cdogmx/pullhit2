<?php

namespace App\Http\Controllers\Web;

use App\Actions\Collection\AddToCollection;
use App\Actions\Collection\BuildPortfolio;
use App\Actions\Collection\ExportCollectionCsv;
use App\Actions\Collection\RemoveFromCollection;
use App\Actions\Collection\UpdateCollectionItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\StoreCollectionItemRequest;
use App\Http\Resources\CollectionItemResource;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A user's collection + portfolio (Inertia). Always free; thin — delegates to the
 * same Collection Actions the API uses (§2).
 */
class CollectionController extends Controller
{
    public function index(Request $request, BuildPortfolio $build): Response
    {
        $portfolio = $build($request->user());

        return Inertia::render('collection/index', [
            'holdings' => CollectionItemResource::collection($portfolio['items'])->resolve(),
            'summary' => $portfolio['summary'],
            'allocation' => $portfolio['allocation'],
            'gainers' => $portfolio['gainers'],
            'decliners' => $portfolio['decliners'],
        ]);
    }

    public function export(Request $request, ExportCollectionCsv $export): StreamedResponse
    {
        ['headers' => $headers, 'rows' => $rows] = $export($request->user());

        $filename = 'cardfoo-collection-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function store(StoreCollectionItemRequest $request, AddToCollection $add): RedirectResponse
    {
        $data = $request->validated();
        $item = CatalogItem::findOrFail($data['catalog_item_id']);

        $add($request->user(), $item, $data);

        return back()->with('success', 'Added to your collection.');
    }

    public function update(Request $request, CollectionItem $collectionItem, UpdateCollectionItem $update): RedirectResponse
    {
        $this->authorize('update', $collectionItem);

        $update($collectionItem, $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_for_sale' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]));

        return back()->with('success', 'Collection updated.');
    }

    public function destroy(CollectionItem $collectionItem, RemoveFromCollection $remove): RedirectResponse
    {
        $this->authorize('delete', $collectionItem);

        $remove($collectionItem);

        return back()->with('success', 'Removed from your collection.');
    }
}
