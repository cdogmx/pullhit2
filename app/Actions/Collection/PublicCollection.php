<?php

namespace App\Actions\Collection;

use App\Models\Collection;
use App\Models\CollectionFolder;
use App\Models\CollectionItem;
use App\Models\GradingCompany;

/**
 * Build the PUBLIC view of a named collection: cards, states, quantities, and
 * market values, sorted by value. Deliberately omits cost basis and P&L — those
 * stay private even when the collection is public.
 *
 * When the viewer owns the collection ($owner), each holding also carries the
 * fields the full-edit modal needs, plus the owner's other collections and the
 * grading-company options, so they can edit in place from their public page.
 */
class PublicCollection
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Collection $collection, bool $owner = false, ?CollectionFolder $folder = null): array
    {
        $user = $collection->user;
        $items = $collection->items()
            ->when($folder, fn ($q) => $q->where('folder', $folder->name))
            ->with(['catalogItem.set', 'catalogItem.productLine', 'catalogItem.marketValues', 'gradingCompany'])
            ->get();

        $rows = $items->map(function (CollectionItem $ci) use ($owner) {
            $unit = $ci->currentUnitValue();
            $value = $unit !== null ? $unit * $ci->quantity : null;
            $catalog = $ci->catalogItem;

            $holding = [
                'catalog_item_id' => $catalog?->id,
                'url' => $catalog?->path(),
                'name' => $catalog?->display_name,
                'number' => $catalog?->number,
                'image_url' => $catalog?->primary_image_path
                    ?? ($catalog?->external_ids['ptcgio_image'] ?? null),
                'set' => $catalog?->set?->name,
                'state_label' => $ci->stateLabel(),
                'quantity' => $ci->quantity,
                'unit_value' => $unit,
                'market_value' => $value,
                'currency' => 'USD',
                'added_at' => $ci->created_at?->toIso8601String(),
            ];

            if ($owner) {
                // Fields the full-edit modal needs (owner only).
                $holding += [
                    'id' => $ci->id,
                    'condition' => $ci->condition?->value,
                    'grade' => $ci->grade,
                    'grading_company' => $ci->gradingCompany ? ['id' => $ci->gradingCompany->id] : null,
                    'is_for_sale' => $ci->is_for_sale,
                    'notes' => $ci->notes,
                    'folder' => $ci->folder,
                ];
            }

            return ['value' => $value, 'holding' => $holding];
        });

        $totalValue = (int) $rows->sum('value');

        return [
            'owner' => [
                // Public pages identify the owner by their handle, never their real name.
                'username' => $user->username,
                'avatar' => $user->avatar,
                'level' => $user->level()['name'],
                'bio' => $user->bio,
                'location' => $user->location,
                'website' => $user->website,
                'x_handle' => $user->x_handle,
                'instagram_handle' => $user->instagram_handle,
            ],
            'collection' => [
                'name' => $collection->name,
                'slug' => $collection->slug,
                'is_default' => $collection->is_default,
            ],
            'folder' => $folder ? ['name' => $folder->name, 'slug' => $folder->slug] : null,
            'summary' => [
                'total_value' => $totalValue,
                'item_count' => $items->count(),
                'card_count' => (int) $items->sum('quantity'),
                'currency' => 'USD',
            ],
            'holdings' => $rows
                ->sortByDesc('value')
                ->pluck('holding')
                ->values()
                ->all(),
            // Owner-only edit context — the modal's move + graded-state options.
            'canEdit' => $owner,
            'collections' => $owner
                ? $user->collections()->orderByDesc('is_default')->orderBy('name')
                    ->get(['id', 'name', 'slug'])->all()
                : [],
            'gradingCompanies' => $owner
                ? GradingCompany::orderBy('name')->get(['id', 'slug', 'name', 'scale_max', 'supports_half_grades'])->all()
                : [],
        ];
    }
}
