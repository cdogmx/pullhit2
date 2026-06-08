<?php

namespace App\Support\Verticals;

/**
 * The data type of a vertical attribute (facet). Drives validation rules and,
 * later, the dynamic form/filter controls rendered for each facet.
 */
enum AttributeType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Enum = 'enum';

    /**
     * Laravel validation rules for a value of this type.
     *
     * @param  array<int, string>|null  $options  allowed values for Enum
     * @return array<int, string>
     */
    public function validationRules(?array $options = null): array
    {
        return match ($this) {
            self::String, self::Text => ['string'],
            self::Integer => ['integer'],
            self::Decimal => ['numeric'],
            self::Boolean => ['boolean'],
            self::Enum => ['string', 'in:'.implode(',', $options ?? [])],
        };
    }
}
