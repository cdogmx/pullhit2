<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Normalize social/website input before validation: strip a leading "@" from
     * handles and prepend https:// to a scheme-less website so "example.com" is
     * accepted by the `url` rule.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->website) && trim($this->website) !== '') {
            $url = trim($this->website);
            $merge['website'] = preg_match('#^https?://#i', $url) ? $url : 'https://'.$url;
        }

        foreach (['x_handle', 'instagram_handle'] as $field) {
            if (is_string($this->{$field})) {
                $merge[$field] = ltrim(trim($this->{$field}), '@') ?: null;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            'bio' => ['nullable', 'string', 'max:300'],
            'location' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url', 'max:200'],
            'x_handle' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
            'instagram_handle' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.]+$/'],
        ];
    }
}
