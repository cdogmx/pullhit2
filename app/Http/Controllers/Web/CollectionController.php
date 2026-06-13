<?php

namespace App\Http\Controllers\Web;

use App\Actions\Collection\AddToCollection;
use App\Actions\Collection\BuildPortfolio;
use App\Actions\Collection\ExportCollectionCsv;
use App\Actions\Collection\RemoveFromCollection;
use App\Actions\Collection\UpdateCollectionItem;
use App\Actions\Collection\PublicCollection;
use App\Actions\Import\BuildImportPreview;
use App\Actions\Import\ImportCollection;
use App\Models\User;
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
        $user = $request->user();
        $portfolio = $build($user);

        return Inertia::render('collection/index', [
            'holdings' => CollectionItemResource::collection($portfolio['items'])->resolve(),
            'summary' => $portfolio['summary'],
            'allocation' => $portfolio['allocation'],
            'gainers' => $portfolio['gainers'],
            'decliners' => $portfolio['decliners'],
            'publicUrl' => $user->is_collection_public && $user->username
                ? url("/collection/{$user->username}")
                : null,
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

    /** Public, shareable collection page — only when the owner opted in. */
    public function publicShow(string $username, PublicCollection $build): Response
    {
        $user = User::where('username', $username)->first();

        abort_unless($user && $user->is_collection_public, 404);

        return Inertia::render('collection/public', $build($user));
    }

    public function importForm(): Response
    {
        return Inertia::render('collection/import');
    }

    public function importPreview(Request $request, BuildImportPreview $build): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $csv = (string) file_get_contents($request->file('file')->getRealPath());

        return Inertia::render('collection/import', $build($csv));
    }

    public function importStore(Request $request, ImportCollection $import): RedirectResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.catalog_item_id' => ['required', 'integer', 'exists:catalog_items,id'],
            'rows.*.condition' => ['nullable', 'string', 'max:8'],
            'rows.*.grading_company_id' => ['nullable', 'integer', 'exists:grading_companies,id'],
            'rows.*.grade' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'rows.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'rows.*.unit_cost' => ['nullable', 'integer', 'min:0'],
            'rows.*.acquired_at' => ['nullable', 'date'],
            'rows.*.folder' => ['nullable', 'string', 'max:255'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $count = $import($request->user(), $data['rows']);

        return redirect('/collection')->with('success', "Imported {$count} cards from PriceCharting.");
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
