<?php

namespace App\Http\Requests\Scanning;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Teaches the recognition cache: a scan with this perceptual hash was confirmed
 * to be this catalog item. Sent after the user adds a scanned card.
 */
class ScanConfirmRequest extends FormRequest
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
            'fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{16}$/'],
            'catalog_item_id' => ['required', 'integer', 'exists:catalog_items,id'],
        ];
    }
}
