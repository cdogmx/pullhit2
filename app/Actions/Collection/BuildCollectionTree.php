<?php

namespace App\Actions\Collection;

use App\Models\CollectionFolder;
use App\Models\CollectionItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The user's collection → folder hierarchy for navigation (sidebar tree, the
 * dashboard "Your collections" card). Each collection lists its folders, which
 * are ALWAYS scoped to that one collection — a folder is a per-collection
 * grouping keyed by the holding's `folder` string, materialised to a
 * CollectionFolder row (slug + visibility) on demand.
 *
 * Cheap by default (structural: names + counts, ~3 queries) so it can be shared
 * on every authed page. Pass pre-loaded portfolio items to also fold in the
 * market value per collection and per folder — without re-querying — for the
 * dashboard card.
 */
class BuildCollectionTree
{
    /**
     * @param  Collection<int, CollectionItem>|null  $valuedItems  pre-loaded items
     *                                                             (with catalogItem.marketValues + gradingCompany) to compute values
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(User $user, ?Collection $valuedItems = null): array
    {
        $collections = $user->collections()->withCount('items')
            ->orderByDesc('is_default')->orderBy('sort')->orderBy('name')->get();

        if ($collections->isEmpty()) {
            return [];
        }

        // Folder item-counts per collection (name-keyed, from the holdings).
        $folderRows = CollectionItem::query()
            ->where('user_id', $user->id)
            ->whereNotNull('folder')->where('folder', '!=', '')
            ->selectRaw('collection_id, folder, count(*) as c')
            ->groupBy('collection_id', 'folder')
            ->get()
            ->groupBy('collection_id');

        // Existing folder metadata (slug/visibility) in one query; missing ones
        // are materialised on demand (rare — only the first time a name appears).
        $metaByCollection = CollectionFolder::whereIn('collection_id', $collections->pluck('id'))
            ->get()->groupBy('collection_id');

        [$valueByCollection, $valueByFolder] = $this->values($valuedItems);

        return $collections->map(function ($c) use ($folderRows, $metaByCollection, $valuedItems, $valueByCollection, $valueByFolder) {
            $existing = ($metaByCollection[$c->id] ?? collect());
            $counts = ($folderRows[$c->id] ?? collect())->pluck('c', 'folder');

            // Union: every metadata row (incl. empty folders the user created) PLUS
            // any folder name that has holdings but no row yet (materialise it).
            foreach ($counts->keys()->diff($existing->pluck('name')) as $name) {
                $existing->push($c->ensureFolder((string) $name));
            }

            $folders = $existing
                ->map(fn ($folder) => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'slug' => $folder->slug,
                    'is_public' => $folder->is_public,
                    'items_count' => (int) ($counts[$folder->name] ?? 0),
                    'value' => $valuedItems ? ($valueByFolder["{$c->id}|{$folder->name}"] ?? 0) : null,
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            return [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'is_default' => $c->is_default,
                'is_public' => $c->is_public,
                'items_count' => $c->items_count,
                'value' => $valuedItems ? ($valueByCollection[$c->id] ?? 0) : null,
                'folders' => $folders,
            ];
        })->all();
    }

    /**
     * Sum market value per collection and per (collection, folder) from already
     * loaded items — no extra queries.
     *
     * @param  Collection<int, CollectionItem>|null  $items
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private function values(?Collection $items): array
    {
        $byCollection = [];
        $byFolder = [];

        if ($items === null) {
            return [$byCollection, $byFolder];
        }

        foreach ($items as $ci) {
            $unit = $ci->currentUnitValue();
            if ($unit === null) {
                continue;
            }

            $value = $unit * $ci->quantity;
            $byCollection[$ci->collection_id] = ($byCollection[$ci->collection_id] ?? 0) + $value;

            if ($ci->folder !== null && $ci->folder !== '') {
                $key = "{$ci->collection_id}|{$ci->folder}";
                $byFolder[$key] = ($byFolder[$key] ?? 0) + $value;
            }
        }

        return [$byCollection, $byFolder];
    }
}
