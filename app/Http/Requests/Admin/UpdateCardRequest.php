<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate an admin card edit. Authorization is the `admin` route middleware.
 */
class UpdateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'rarity' => ['sometimes', 'string', 'max:100'],
            'variant' => ['sometimes', Rule::in(['normal', 'holo', 'reverse_holo', '1st_edition', 'unlimited'])],
            'illustrator' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'primary_image_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
