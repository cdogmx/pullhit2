<?php

namespace App\Enums;

/**
 * The kinds of accepted contribution that earn points. Point values live in
 * config('community.points') so they're tunable without a deploy.
 */
enum ContributionType: string
{
    case EditSuggestion = 'edit_suggestion';
    case MissingCard = 'missing_card';
    case MissingSet = 'missing_set';

    public function label(): string
    {
        return match ($this) {
            self::EditSuggestion => 'Card edit',
            self::MissingCard => 'Missing card',
            self::MissingSet => 'Missing set',
        };
    }

    /** Points awarded for this contribution (config-driven, with a fallback). */
    public function points(): int
    {
        return (int) (config('community.points.'.$this->value) ?? 0);
    }
}
