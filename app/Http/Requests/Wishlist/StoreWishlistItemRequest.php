<?php

namespace App\Http\Requests\Wishlist;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWishlistItemRequest extends FormRequest
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
            'catalog_item_id' => ['required', 'integer', 'exists:catalog_items,id'],
            // Target an existing wishlist (must be the user's) or name a new one.
            'wishlist_id' => ['nullable', 'integer', Rule::exists('wishlists', 'id')->where('user_id', $this->user()?->id)],
            'new_wishlist_name' => ['nullable', 'string', 'max:60'],
            'target_price' => ['nullable', 'integer', 'min:0'], // cents
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
