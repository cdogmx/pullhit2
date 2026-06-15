<?php

namespace App\Http\Requests\Catalog;

use App\Support\Verticals\Definitions\TcgVertical;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemEditRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:50'],
            'rarity' => ['nullable', 'string', 'max:64'],
            'variant' => ['nullable', Rule::in(['normal', 'holo', 'reverse_holo'])],
            'edition' => ['nullable', Rule::in(['unlimited', 'shadowless', 'first_edition'])],
            'language' => ['nullable', Rule::in(TcgVertical::LANGUAGES)],
            'illustrator' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
