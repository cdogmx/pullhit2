<?php

namespace App\Http\Requests\Collection;

use App\Enums\Condition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a bulk "add to collection" request — many cards, one shared state.
 * Same shape as StoreCollectionItemRequest but keyed on `catalog_item_ids`; the
 * condition/grade, quantity, and cost apply to every card in the batch. Money is
 * integer minor units (cents); the client converts dollars before posting.
 */
class BulkStoreCollectionItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Capped like the other bulk endpoints so one request can't run an
            // unbounded per-card transaction loop.
            'catalog_item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'catalog_item_ids.*' => ['integer'],
            // Target an existing collection (must be the user's) or name a new one.
            'collection_id' => ['nullable', 'integer', Rule::exists('collections', 'id')->where('user_id', $this->user()?->id)],
            'new_collection_name' => ['nullable', 'string', 'max:60'],
            'condition' => ['nullable', Rule::enum(Condition::class), 'required_without:grading_company_id'],
            'grading_company_id' => ['nullable', 'integer', 'exists:grading_companies,id'],
            'grade' => ['nullable', 'numeric', 'min:1', 'max:10', 'required_with:grading_company_id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_cost' => ['required', 'integer', 'min:0'],
            'fees' => ['nullable', 'integer', 'min:0'],
            'acquired_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'folder' => ['nullable', 'string', 'max:255'],
        ];
    }
}
