<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Normalizes the browse/search query string for both the web and API catalog
 * controllers. Allow-lists sort/direction/view and clamps per_page so the
 * shared SearchCatalog action receives clean, bounded input.
 */
class SearchCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'vertical' => ['nullable', 'string', 'max:64'],
            'product_line' => ['nullable', 'string', 'max:64'],
            'series' => ['nullable', 'string', 'max:64'],
            'set' => ['nullable', 'string', 'max:64'],
            'subset' => ['nullable', 'string', 'max:32'],
            'item_type' => ['nullable', Rule::in(array_map(fn (ItemType $t) => $t->value, ItemType::cases()))],
            'language' => ['nullable', 'string', 'max:16'],
            'rarity' => ['nullable', 'string', 'max:64'],
            'variant' => ['nullable', 'string', 'max:64'],
            'edition' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', Rule::in(['number', 'name', 'newest', 'set', 'price', 'change'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'group' => ['nullable', 'boolean'],
            // Force the flat card grid (skip the set-tile drill-down).
            'all' => ['nullable', 'boolean'],
            'view' => ['nullable', Rule::in(['grid', 'list'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Normalized filters for the SearchCatalog action + the UI's current state.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'q' => $v['q'] ?? null,
            'vertical' => $v['vertical'] ?? null,
            'product_line' => $v['product_line'] ?? null,
            'series' => $v['series'] ?? null,
            'set' => $v['set'] ?? null,
            'subset' => $v['subset'] ?? null,
            'item_type' => $v['item_type'] ?? null,
            'language' => $v['language'] ?? null,
            'rarity' => $v['rarity'] ?? null,
            'variant' => $v['variant'] ?? null,
            'edition' => $v['edition'] ?? null,
            'sort' => $v['sort'] ?? 'number',
            'direction' => $v['direction'] ?? 'asc',
            'group' => $this->boolean('group'),
            'all' => $this->boolean('all'),
            'view' => $v['view'] ?? 'grid',
            'per_page' => min(max((int) ($v['per_page'] ?? 24), 1), 96),
        ];
    }
}
