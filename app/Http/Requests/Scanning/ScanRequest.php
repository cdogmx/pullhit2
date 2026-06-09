<?php

namespace App\Http\Requests\Scanning;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A scan submission: a base64 image (no data: prefix), its media type, and the
 * mode. The client downscales before encoding, so the payload stays small.
 */
class ScanRequest extends FormRequest
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
            'image' => ['required', 'string', 'max:12000000'], // ~9MB decoded
            'media_type' => ['required', Rule::in(['image/jpeg', 'image/png', 'image/webp'])],
            'mode' => ['required', Rule::in(['single', 'bulk'])],
        ];
    }
}
