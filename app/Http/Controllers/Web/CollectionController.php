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
use App\Support\Membership\Entitlements;
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

        // Ensure the user has a default collection, then pick the active one.
        $default = $user->defaultCollection();
        $collections = $user->collections()->withCount('items')
            ->orderByDesc('is_default')->orderBy('sort')->orderBy('name')->get();

        $active = $collections->firstWhere('slug', $request->query('collection')) ?? $default;

        $portfolio = $build($user, $active->id);

        $publicUrl = $active->is_public && $user->username
            ? url('/collection/'.$user->username.($active->is_default ? '' : '/'.$active->slug))
            : null;

        return Inertia::render('collection/index', [
            'collections' => $collections->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'is_public' => $c->is_public,
                'is_default' => $c->is_default,
                'items_count' => $c->items_count,
            ]),
            'activeCollection' => $active->slug,
            'collectionLimit' => ($lim = Entitlements::for($user)->collectionLimit()) === PHP_INT_MAX ? null : $lim,
            'holdings' => CollectionItemResource::collection($portfolio['items'])->resolve(),
            'summary' => $portfolio['summary'],
            'allocation' => $portfolio['allocation'],
            'gainers' => $portfolio['gainers'],
            'decliners' => $portfolio['decliners'],
            'publicUrl' => $publicUrl,
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

    /** Public, shareable collection page — the owner's default collection. */
    public function publicShow(string $username, PublicCollection $build): Response
    {
        return $this->renderPublic($username, null, $build);
    }

    /** Public page for a specific named collection. */
    public function publicShowCollection(string $username, string $collectionSlug, PublicCollection $build): Response
    {
        return $this->renderPublic($username, $collectionSlug, $build);
    }

    private function renderPublic(string $username, ?string $slug, PublicCollection $build): Response
    {
        $user = User::where('username', $username)->first();
        abort_unless((bool) $user, 404);

        $collection = $slug !== null
            ? $user->collections()->where('slug', $slug)->first()
            : $user->collections()->where('is_default', true)->first();

        abort_unless($collection && $collection->is_public, 404);

        $data = $build($collection);

        // Server-rendered share meta (social scrapers don't run JS).
        $title = $collection->is_default
            ? "{$user->username}'s collection"
            : "{$user->username}'s {$collection->name} collection";
        $data['meta'] = [
            'title' => $title,
            'description' => number_format($data['summary']['card_count']).' cards · '
                .'$'.number_format($data['summary']['total_value'] / 100, 2)
                .' on CardFoo.',
        ];

        return Inertia::render('collection/public', $data);
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
            // Move a holding to another of the user's collections.
            'collection_id' => ['sometimes', 'integer', \Illuminate\Validation\Rule::exists('collections', 'id')->where('user_id', $request->user()->id)],
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
