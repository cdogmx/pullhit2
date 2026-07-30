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
     * @param  bool  $variantDefining  whether this facet distinguishes printings of the
     *                                 same card (e.g. variant/finish). Excluded from base_key.
     * @param  bool  $identityDefining  whether this facet is part of WHICH item this is,
     *                                  rather than a description of it. Only these reach
     *                                  identity_hash — a descriptive facet (hp, illustrator)
     *                                  must never fork identity, or two sources that
     *                                  populate different fields duplicate the card.
     *                                  $variantDefining implies this.
     */
    public function __construct(
        public string $key,
        public string $label,
        public AttributeType $type,
        public bool $required = false,
        public bool $searchable = false,
        public bool $indexed = false,
        public ?array $options = null,
        public bool $variantDefining = false,
        public bool $identityDefining = false,
    ) {}

    /** A printing-defining facet is by definition part of the item's identity. */
    public function definesIdentity(): bool
    {
        return $this->identityDefining || $this->variantDefining;
    }

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
        bool $variantDefining = false,
        bool $identityDefining = false,
    ): self {
        return new self($key, $label, $type, $required, $searchable, $indexed, $options, $variantDefining, $identityDefining);
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
