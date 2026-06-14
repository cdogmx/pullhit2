<?php

namespace App\Support\Reconcile;

/**
 * One proposed reconciliation change for a set: an add, a label fix, or a link
 * to a PriceCharting product. Plain data — {@see \App\Actions\Reconcile\ApplySet}
 * turns high-confidence ones into catalog writes; low-confidence ones queue for
 * review.
 */
readonly class ReconcileChange
{
    public const ADD_PRINTING = 'add_printing';      // missing edition/variant of a card we have
    public const ADD_ERROR_VARIANT = 'add_error';    // missing error/promo printing
    public const ADD_CARD = 'add_card';              // numbered card we lack entirely
    public const ADD_SEALED = 'add_sealed';          // booster box/pack/etc.
    public const FIX_LABEL = 'fix_label';            // correct an existing item's attributes
    public const LINK = 'link';                      // attach pricecharting_id to a match

    /**
     * @param  array<string, mixed>  $attributes  full attributes for an add
     * @param  array<string, array{0: mixed, 1: mixed}>  $diff  field => [old, new] for a fix
     * @param  array<string, int|null>  $prices  PriceCharting current prices (cents)
     */
    public function __construct(
        public string $action,
        public string $pcId,
        public string $label,
        public string $confidence,            // 'high' | 'low'
        public string $reason,
        public ?int $catalogItemId = null,    // LINK / FIX_LABEL target
        public ?int $baseItemId = null,       // card to inherit name/rarity/image from (adds)
        public ?string $name = null,
        public ?string $number = null,
        public array $attributes = [],
        public array $diff = [],
        public array $prices = [],
    ) {}

    public function isAdd(): bool
    {
        return in_array($this->action, [self::ADD_PRINTING, self::ADD_ERROR_VARIANT, self::ADD_CARD, self::ADD_SEALED], true);
    }
}
