<?php

namespace App\Http\Requests\Collection;

use App\Enums\Condition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate an "add to collection" request. A copy is either raw (a condition) or
 * graded (company + grade) — at least one identity is required. Money (`unit_cost`,
 * `fees`) is integer minor units (cents); the client converts dollars before posting.
 */
class StoreCollectionItemRequest extends FormRequest
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
            'catalog_item_id' => ['required', 'integer', 'exists:catalog_items,id'],
            'condition' => ['nullable', Rule::enum(Condition::class), 'required_without:grading_company_id'],
            'grading_company_id' => ['nullable', 'integer', 'exists:grading_companies,id'],
            'grade' => ['nullable', 'numeric', 'min:1', 'max:10', 'required_with:grading_company_id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_cost' => ['required', 'integer', 'min:0'],
            'fees' => ['nullable', 'integer', 'min:0'],
            'acquired_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'is_for_sale' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
