<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\ItemEditSuggestion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Record a user's proposed correction to a catalog item — only the fields that
 * actually differ from the current values, held as `pending` for admin review.
 * One open suggestion per (user, item): resubmitting updates it.
 */
class SubmitItemEdit
{
    /**
     * @param  array<string, mixed>  $changes  proposed values keyed by field
     */
    public function __invoke(User $user, CatalogItem $item, array $changes, ?string $note): ItemEditSuggestion
    {
        $diff = $this->diff($item, $changes);

        if ($diff === [] && ($note === null || trim($note) === '')) {
            throw ValidationException::withMessages(['changes' => 'Change at least one field or add a note.']);
        }

        $suggestion = ItemEditSuggestion::firstOrNew([
            'user_id' => $user->id,
            'catalog_item_id' => $item->id,
            'status' => 'pending',
        ]);

        $suggestion->fill(['changes' => $diff, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null])->save();

        return $suggestion;
    }

    /**
     * Keep only editable fields whose proposed value differs from the item's
     * current value (empty string → null = clear).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function diff(CatalogItem $item, array $changes): array
    {
        $attributes = $item->getAttribute('attributes') ?? [];
        $out = [];

        foreach (ItemEditSuggestion::editableFields() as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $proposed = $changes[$field];
            $proposed = is_string($proposed) ? trim($proposed) : $proposed;
            if ($proposed === '') {
                $proposed = null;
            }

            // Never let name be blanked.
            if ($field === 'name' && $proposed === null) {
                continue;
            }

            $current = in_array($field, ItemEditSuggestion::TOP_LEVEL, true)
                ? $item->{$field}
                : ($attributes[$field] ?? null);

            if ($proposed !== $current) {
                $out[$field] = $proposed;
            }
        }

        return $out;
    }
}
