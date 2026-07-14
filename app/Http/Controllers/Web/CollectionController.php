<?php

namespace App\Http\Controllers\Web;

use App\Actions\Collection\AddToCollection;
use App\Actions\Collection\BuildPortfolio;
use App\Actions\Collection\CreateCollection;
use App\Actions\Collection\ExportCollectionCsv;
use App\Actions\Collection\PublicCollection;
use App\Actions\Collection\RemoveFromCollection;
use App\Actions\Collection\SetCollectionQuantity;
use App\Actions\Collection\UpdateCollectionItem;
use App\Actions\Import\BuildImportPreview;
use App\Actions\Import\ImportCollection;
use App\Enums\Condition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\StoreCollectionItemRequest;
use App\Http\Resources\CollectionItemResource;
use App\Models\CatalogItem;
use App\Models\Collection;
use App\Models\CollectionFolder;
use App\Models\CollectionItem;
use App\Models\GradingCompany;
use App\Models\User;
use App\Support\Membership\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'folders' => $this->buildFolders($active, $user),
            // Options for the full-edit modal's graded-state picker.
            'gradingCompanies' => GradingCompany::orderBy('name')
                ->get(['id', 'slug', 'name', 'scale_max', 'supports_half_grades']),
        ]);
    }

    /**
     * The active collection's folders (from the holdings' folder names) with their
     * shareable metadata — slug, public/private, count, and a public link.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFolders(Collection $collection, User $user): array
    {
        $counts = $collection->items()
            ->whereNotNull('folder')->where('folder', '!=', '')
            ->selectRaw('folder, count(*) as c')->groupBy('folder')
            ->pluck('c', 'folder');

        // Materialise a metadata row for every folder that has holdings, then list
        // ALL of the collection's folders — including empty ones the user created
        // up front — with their live counts.
        foreach ($counts->keys() as $name) {
            $collection->ensureFolder((string) $name);
        }

        return $collection->folders()->orderBy('name')->get()->map(fn ($folder) => [
            'id' => $folder->id,
            'name' => $folder->name,
            'slug' => $folder->slug,
            'is_public' => $folder->is_public,
            'items_count' => (int) ($counts[$folder->name] ?? 0),
            'public_url' => $folder->is_public && $user->username
                ? url("/collection/{$user->username}/{$collection->slug}/folder/{$folder->slug}")
                : null,
        ])->values()->all();
    }

    /** Create an (initially empty) folder inside a collection the user owns. */
    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'collection_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:60'],
        ]);

        $collection = Collection::where('id', $data['collection_id'])
            ->where('user_id', $request->user()->id)->firstOrFail();

        $collection->ensureFolder($data['name']);

        return back()->with('success', 'Folder created.');
    }

    /**
     * Delete a folder: its holdings stay in the collection but lose the folder
     * label (they move to "no folder"), then the shareable row is removed.
     */
    public function destroyFolder(Request $request, CollectionFolder $collectionFolder): RedirectResponse
    {
        $collection = $collectionFolder->collection;
        abort_unless($collection->user_id === $request->user()->id, 403);

        $collection->items()->where('folder', $collectionFolder->name)
            ->update(['folder' => null]);
        $collectionFolder->delete();

        return back()->with('success', 'Folder deleted.');
    }

    /**
     * A single folder within a collection (owner view) — its holdings, a
     * folder-scoped summary, breadcrumb context, and the folder's own share
     * controls. Folders are always scoped to their parent collection.
     */
    public function showFolder(Request $request, CollectionFolder $collectionFolder, BuildPortfolio $build): Response
    {
        $user = $request->user();
        $collection = $collectionFolder->collection;
        abort_unless($collection->user_id === $user->id, 403);

        $portfolio = $build($user, $collection->id, $collectionFolder->name);

        $publicUrl = $collectionFolder->is_public && $user->username
            ? url("/collection/{$user->username}/{$collection->slug}/folder/{$collectionFolder->slug}")
            : null;

        return Inertia::render('collection/folder', [
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'slug' => $collection->slug,
                'is_default' => $collection->is_default,
            ],
            'folder' => [
                'id' => $collectionFolder->id,
                'name' => $collectionFolder->name,
                'slug' => $collectionFolder->slug,
                'is_public' => $collectionFolder->is_public,
                'public_url' => $publicUrl,
            ],
            'holdings' => CollectionItemResource::collection($portfolio['items'])->resolve(),
            'summary' => $portfolio['summary'],
            'gradingCompanies' => GradingCompany::orderBy('name')
                ->get(['id', 'slug', 'name', 'scale_max', 'supports_half_grades']),
        ]);
    }

    /** Toggle a folder's public/private visibility (owner only). */
    public function updateFolder(Request $request, CollectionFolder $collectionFolder): RedirectResponse
    {
        abort_unless($collectionFolder->collection->user_id === $request->user()->id, 403);

        $collectionFolder->update($request->validate([
            'is_public' => ['required', 'boolean'],
        ]));

        return back()->with('success', 'Folder visibility updated.');
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

    /**
     * Public page for a single folder within a collection. Gated on the FOLDER's
     * own visibility — a public folder is shareable even when its collection is
     * private, so an owner can expose just one folder.
     */
    public function publicFolder(string $username, string $collectionSlug, string $folderSlug, PublicCollection $build): Response
    {
        $user = User::where('username', $username)->first();
        abort_unless((bool) $user, 404);

        $collection = $user->collections()->where('slug', $collectionSlug)->first();
        abort_unless((bool) $collection, 404);

        $folder = $collection->folders()->where('slug', $folderSlug)->first();
        abort_unless($folder && $folder->is_public, 404);

        $owner = auth()->id() === $user->id;
        $data = $build($collection, $owner, $folder);

        $data['meta'] = [
            'title' => "{$user->username}'s {$folder->name} folder",
            'description' => number_format($data['summary']['card_count']).' cards · '
                .'$'.number_format($data['summary']['total_value'] / 100, 2)
                .' on CardFoo.',
        ];

        return Inertia::render('collection/public', $data);
    }

    private function renderPublic(string $username, ?string $slug, PublicCollection $build): Response
    {
        $user = User::where('username', $username)->first();
        abort_unless((bool) $user, 404);

        $collection = $slug !== null
            ? $user->collections()->where('slug', $slug)->first()
            : $user->collections()->where('is_default', true)->first();

        abort_unless($collection && $collection->is_public, 404);

        // The owner viewing their own public page can edit holdings in place.
        $owner = auth()->id() === $user->id;
        $data = $build($collection, $owner);

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

    public function store(StoreCollectionItemRequest $request, AddToCollection $add, CreateCollection $create): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $item = CatalogItem::findOrFail($data['catalog_item_id']);

        // "New collection…" from the picker — create it (tier-gated) and add there.
        if (! empty($data['new_collection_name'])) {
            $data['collection_id'] = $create($user, $data['new_collection_name'])->id;
        }

        $add($user, $item, $data);

        return back()->with('success', 'Added to your collection.');
    }

    /**
     * The user's collections + whether they can create another (for the
     * "add to collection" picker).
     */
    public function targets(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = Entitlements::for($user)->collectionLimit();

        return response()->json([
            'targets' => $user->collections()->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'is_default'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'is_default' => (bool) $c->is_default]),
            'can_create' => $user->collections()->count() < $limit,
            'limit' => $limit === PHP_INT_MAX ? null : $limit,
        ]);
    }

    /**
     * How many copies of a card the signed-in user already owns, summed across
     * all their collections — lets the scanner flag "already in your collection".
     */
    public function owned(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        return response()->json([
            'quantity' => (int) $request->user()->collectionItems()
                ->where('catalog_item_id', $catalogItem->id)
                ->sum('quantity'),
        ]);
    }

    /**
     * The viewer's holdings of one card, broken down by collection + priced state
     * — so the "set quantity" modal can pre-fill with what they already own.
     */
    public function holdings(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        return response()->json([
            'default_collection_id' => $request->user()->defaultCollection()->id,
            'holdings' => $request->user()->collectionItems()
                ->where('catalog_item_id', $catalogItem->id)
                ->get(['collection_id', 'condition', 'grading_company_id', 'grade', 'quantity'])
                ->map(fn (CollectionItem $ci) => [
                    'collection_id' => $ci->collection_id,
                    'condition' => $ci->condition?->value,
                    'grading_company_id' => $ci->grading_company_id,
                    'grade' => $ci->grade !== null ? (float) $ci->grade : null,
                    'quantity' => (int) $ci->quantity,
                ]),
        ]);
    }

    /**
     * Set how many copies of a card the viewer owns of a given priced state to an
     * exact number (the "how many do I have" model), creating the collection if
     * asked. Reconciles cost-basis lots to match.
     */
    public function setQuantity(Request $request, CatalogItem $catalogItem, SetCollectionQuantity $set, CreateCollection $create): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'collection_id' => ['nullable', 'integer', Rule::exists('collections', 'id')->where('user_id', $user->id)],
            'new_collection_name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'condition' => ['nullable', Rule::enum(Condition::class)],
            'grading_company_id' => ['nullable', 'integer', 'exists:grading_companies,id'],
            'grade' => ['nullable', 'numeric', 'min:1', 'max:10', 'required_with:grading_company_id'],
            'unit_cost' => ['nullable', 'integer', 'min:0'],
            'acquired_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:60'],
        ]);

        if (! empty($data['new_collection_name'])) {
            $data['collection_id'] = $create($user, $data['new_collection_name'])->id;
        }

        $set($user, $catalogItem, $data, (int) $data['quantity']);

        return back()->with('success', 'Collection updated.');
    }

    public function update(Request $request, CollectionItem $collectionItem, UpdateCollectionItem $update): RedirectResponse
    {
        $this->authorize('update', $collectionItem);

        $update($collectionItem, $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_for_sale' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Priced state: raw condition OR graded company + grade.
            'condition' => ['sometimes', 'nullable', Rule::enum(Condition::class)],
            'grading_company_id' => ['sometimes', 'nullable', 'integer', 'exists:grading_companies,id'],
            'grade' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:10', 'required_with:grading_company_id'],
            // Move a holding to another of the user's collections.
            'collection_id' => ['sometimes', 'integer', Rule::exists('collections', 'id')->where('user_id', $request->user()->id)],
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
