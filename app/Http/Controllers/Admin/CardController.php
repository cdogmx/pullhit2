<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\CatalogFilterOptions;
use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\UpdateCatalogItem;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCardRequest;
use App\Http\Requests\Admin\UpdateCardRequest;
use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\Set;
use App\Support\Verticals\Definitions\TcgVertical;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin card editor — search the catalog and fix individual cards.
 */
class CardController extends Controller
{
    public function index(Request $request, CatalogFilterOptions $options): Response
    {
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'set' => (string) $request->query('set', ''),
            'rarity' => (string) $request->query('rarity', ''),
            'variant' => (string) $request->query('variant', ''),
            'language' => (string) $request->query('language', ''),
            'sort' => (string) $request->query('sort', 'name'),
        ];

        $query = CatalogItem::query()
            ->with('set')
            ->when($f['q'] !== '', fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->where('name', 'like', "%{$f['q']}%")->orWhere('number', 'like', "%{$f['q']}%"),
            ))
            ->when($f['set'] !== '', fn (Builder $q) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $f['set'])))
            ->when($f['rarity'] !== '', fn (Builder $q) => $q->where('attributes->rarity', $f['rarity']))
            ->when($f['variant'] !== '', fn (Builder $q) => $q->where('attributes->variant', $f['variant']))
            ->when($f['language'] !== '', fn (Builder $q) => $q->where('language', $f['language']));

        $this->sort($query, $f['sort']);

        $paginator = $query->paginate(30)->withQueryString();

        // Rarities narrow to the selected set; sets/variants/languages are global.
        $o = $options(['set' => $f['set']]);

        return Inertia::render('admin/cards', [
            'items' => collect($paginator->items())->map(fn (CatalogItem $i) => $this->row($i)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'options' => [
                'sets' => $o['sets'],
                'rarities' => $o['rarities'],
                'variants' => $o['variants'],
                'languages' => $o['languages'],
            ],
            // Options for the "New card" form: every set to attach to, plus the
            // full TCG language + variant vocabularies (not just what's present).
            'createOptions' => [
                'sets' => Set::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Set $s) => ['id' => $s->id, 'name' => $s->name]),
                'languages' => TcgVertical::LANGUAGES,
                'variants' => $o['variants'] ?: ['normal', 'holo', 'reverse_holo'],
            ],
            'filters' => $f,
        ]);
    }

    public function store(StoreCardRequest $request, CreateCatalogItem $create): RedirectResponse
    {
        $data = $request->validated();

        $set = Set::with('productLine.vertical')->findOrFail($data['set_id']);

        $attributes = array_filter([
            'language' => $data['language'],
            'variant' => $data['variant'],
            'rarity' => $data['rarity'] ?? null,
            'illustrator' => $data['illustrator'] ?? null,
            'hp' => isset($data['hp']) && $data['hp'] !== '' ? (int) $data['hp'] : null,
            'type' => $data['type'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $create(
            vertical: $set->productLine->vertical,
            productLine: $set->productLine,
            set: $set,
            itemType: ItemType::Single,
            name: $data['name'],
            number: $data['number'] ?? null,
            attributes: $attributes,
            externalIds: [],
            primaryImagePath: $data['primary_image_path'] ?? null,
        );

        return back()->with('success', 'Card created.');
    }

    /** @param  Builder<CatalogItem>  $query */
    protected function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'views' => $query->orderByDesc('popularity')->orderBy('name'),
            'updated' => $query->orderByDesc('updated_at'),
            'number' => $query->orderByRaw('CAST(number AS UNSIGNED)')->orderBy('number'),
            default => $query->orderBy('name'),
        };
    }

    public function update(UpdateCardRequest $request, CatalogItem $catalogItem, UpdateCatalogItem $update): RedirectResponse
    {
        $update($catalogItem, $request->validated());

        return back()->with('success', 'Card updated.');
    }

    /** Admin "Refresh now" — force an eBay pull, bypassing the 12h freshness guard. */
    public function refresh(CatalogItem $catalogItem): JsonResponse
    {
        RefreshEbaySoldComps::dispatch($catalogItem->id, force: true);

        return response()->json(['ok' => true]);
    }

    public function destroy(CatalogItem $catalogItem): RedirectResponse
    {
        $catalogItem->marketValues()->delete();
        $catalogItem->saleObservations()->delete();
        CollectionItem::where('catalog_item_id', $catalogItem->id)->delete();
        $catalogItem->delete();

        return back()->with('success', 'Card deleted.');
    }

    /** @return array<string, mixed> */
    protected function row(CatalogItem $item): array
    {
        $a = $item->attributes ?? [];

        return [
            'id' => $item->id,
            'name' => $item->name,
            'number' => $item->number,
            'language' => $item->language,
            'set' => $item->set?->name,
            'image_url' => $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null),
            'primary_image_path' => $item->primary_image_path,
            'rarity' => $a['rarity'] ?? null,
            'variant' => $a['variant'] ?? null,
            'illustrator' => $a['illustrator'] ?? null,
            'hp' => $a['hp'] ?? null,
            'type' => $a['type'] ?? null,
            'views' => (int) $item->popularity,
        ];
    }
}
