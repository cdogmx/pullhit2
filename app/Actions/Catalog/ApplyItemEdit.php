<?php

namespace App\Actions\Catalog;

use App\Actions\Community\AwardPoints;
use App\Enums\ContributionType;
use App\Models\CatalogItem;
use App\Models\ItemEditSuggestion;
use App\Models\User;
use App\Notifications\ItemEditReviewed;
use App\Support\Catalog\IdentityHash;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Support\Carbon;

/**
 * Apply an approved edit suggestion to its catalog item: write the proposed
 * name/number + attribute facets, re-validate against the vertical schema, and
 * recompute identity_hash/base_key (name/number/variant facets feed the hash).
 */
class ApplyItemEdit
{
    public function __construct(
        protected VerticalRegistry $registry,
        protected AwardPoints $award,
    ) {}

    public function apply(ItemEditSuggestion $suggestion, User $reviewer): CatalogItem
    {
        $item = $suggestion->catalogItem;
        $item->loadMissing(['vertical', 'productLine', 'set']);

        $attributes = $item->getAttribute('attributes') ?? [];
        $topLevel = [];

        foreach ($suggestion->changes as $field => $value) {
            if (in_array($field, ItemEditSuggestion::TOP_LEVEL, true)) {
                $topLevel[$field] = $value;
            } elseif (in_array($field, ItemEditSuggestion::ATTRIBUTES, true)) {
                if ($value === null) {
                    unset($attributes[$field]);
                } else {
                    $attributes[$field] = $value;
                }
            }
        }

        // Re-validate the merged facets — a bad edit (missing required facet,
        // unknown enum) throws here and can't be applied.
        $validated = $this->registry->validate($item->vertical->slug, $item->item_type->value, $attributes);

        $item->forceFill(array_merge($topLevel, ['attributes' => $validated]));
        $this->rehash($item);
        $item->save();

        $this->resolve($suggestion, 'approved', $reviewer);

        return $item;
    }

    public function reject(ItemEditSuggestion $suggestion, User $reviewer, ?string $note): void
    {
        $this->resolve($suggestion, 'rejected', $reviewer, $note);
    }

    private function resolve(ItemEditSuggestion $suggestion, string $status, User $reviewer, ?string $note = null): void
    {
        $suggestion->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'review_note' => $note,
            'reviewed_at' => Carbon::now(),
        ])->save();

        // Reward the submitter for an accepted fix (idempotent per suggestion).
        if ($status === 'approved' && $suggestion->user) {
            ($this->award)(
                $suggestion->user,
                ContributionType::EditSuggestion,
                $suggestion,
                'Edit approved',
            );
        }

        // Let the submitter know the outcome.
        $suggestion->user?->notify(new ItemEditReviewed($suggestion));
    }

    private function rehash(CatalogItem $item): void
    {
        $args = [
            'verticalSlug' => $item->vertical->slug,
            'productLineSlug' => $item->productLine->slug,
            'setKey' => $item->set?->slug,
            'itemType' => $item->item_type->value,
            'name' => $item->name,
            'number' => $item->number,
        ];
        $attributes = $item->getAttribute('attributes') ?? [];
        $variantKeys = $this->registry->get($item->vertical->slug)->variantDefiningKeys($item->item_type->value);

        $item->forceFill([
            'identity_hash' => IdentityHash::compute(...$args, attributes: $attributes),
            'base_key' => IdentityHash::compute(...$args, attributes: array_diff_key($attributes, array_flip($variantKeys))),
        ]);
    }
}
