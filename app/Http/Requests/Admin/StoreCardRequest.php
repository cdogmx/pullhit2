<?php

namespace App\Http\Requests\Admin;

use App\Support\Verticals\Definitions\TcgVertical;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a hand-created single card. The card inherits its vertical + product
 * line from the chosen set, so only the set + the TCG facets are collected here.
 * `language` and `variant` are required (the registry requires them for Singles).
 * Authorization is the `admin` route middleware.
 */
class StoreCardRequest extends FormRequest
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
            'set_id' => ['required', 'integer', 'exists:sets,id'],
            'name' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:50'],
            'language' => ['required', Rule::in(TcgVertical::LANGUAGES)],
            'variant' => ['required', Rule::in(['normal', 'holo', 'reverse_holo'])],
            'rarity' => ['nullable', 'string', 'max:100'],
            'illustrator' => ['nullable', 'string', 'max:255'],
            'hp' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'type' => ['nullable', 'string', 'max:100'],
            'primary_image_path' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
