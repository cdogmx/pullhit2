<?php

namespace App\Support\Verticals;

/**
 * Declares a single vertical-specific facet: its key in catalog_items.attributes,
 * display label, type, and flags. The registry uses these to validate item
 * attributes and (later) to render forms, filters, and the scan-confirm UI —
 * so no per-vertical UI/validation is hardcoded.
 */
readonly class AttributeDefinition
{
    /**
     * @param  array<int, string>|null  $options  allowed values when type is Enum
     */
    public function __construct(
        public string $key,
        public string $label,
        public AttributeType $type,
        public bool $required = false,
        public bool $searchable = false,
        public bool $indexed = false,
        public ?array $options = null,
    ) {}

    /**
     * Convenience factory keeping definition lists terse and readable.
     *
     * @param  array<int, string>|null  $options
     */
    public static function make(
        string $key,
        string $label,
        AttributeType $type,
        bool $required = false,
        bool $searchable = false,
        bool $indexed = false,
        ?array $options = null,
    ): self {
        return new self($key, $label, $type, $required, $searchable, $indexed, $options);
    }

    /**
     * The Laravel validation rule list for this facet (incl. required/nullable).
     *
     * @return array<int, string>
     */
    public function rules(): array
    {
        return [
            $this->required ? 'required' : 'nullable',
            ...$this->type->validationRules($this->options),
        ];
    }
}
