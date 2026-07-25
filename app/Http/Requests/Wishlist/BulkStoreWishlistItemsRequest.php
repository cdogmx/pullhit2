<?php

namespace App\Http\Requests\Wishlist;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a bulk "add to wishlist" request — many cards onto one list.
 */
class BulkStoreWishlistItemsRequest extends FormRequest
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
            'catalog_item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'catalog_item_ids.*' => ['integer'],
            // Target an existing wishlist (must be the user's) or name a new one.
            'wishlist_id' => ['nullable', 'integer', Rule::exists('wishlists', 'id')->where('user_id', $this->user()?->id)],
            'new_wishlist_name' => ['nullable', 'string', 'max:60'],
        ];
    }
}
