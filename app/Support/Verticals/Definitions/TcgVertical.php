<?php

namespace App\Support\Verticals\Definitions;

use App\Enums\ItemType;
use App\Support\Verticals\AttributeDefinition as Attr;
use App\Support\Verticals\AttributeType as Type;
use App\Support\Verticals\VerticalDefinition;

/**
 * The `tcg` vertical schema (Pokémon-first, but vertical-level — MTG/One Piece
 * are product lines under it). Facets per §4.2. Language is required everywhere:
 * EN/JP/KR equivalents are distinct catalog_items and must never collide.
 */
final class TcgVertical
{
    /** Shared language options across TCG item types. */
    public const LANGUAGES = ['en', 'ja', 'ko', 'zh-CN', 'zh-TW', 'fr', 'de', 'it', 'es', 'pt'];

    /** Sealed product categories (aligned to TCGplayer's sealed taxonomy). */
    public const SEALED_TYPES = [
        'booster_box', 'booster_box_case', 'booster_pack', 'sleeved_booster_pack',
        'booster_bundle', 'elite_trainer_box', 'collection', 'bundle', 'tin',
        'blister', 'build_and_battle', 'checklane', 'other',
    ];

    public static function definition(): VerticalDefinition
    {
        return new VerticalDefinition(
            slug: 'tcg',
            name: 'Trading Card Games',
            attributes: [
                ItemType::Single->value => [
                    Attr::make('language', 'Language', Type::Enum, required: true, searchable: true, indexed: true, options: self::LANGUAGES),
                    Attr::make('rarity', 'Rarity', Type::String, required: true, searchable: true, indexed: true),
                    // variant (holo-ness), edition (print run), and finish (error/
                    // promo tag) each distinguish printings of the same card.
                    Attr::make('variant', 'Variant', Type::Enum, required: true, searchable: true, indexed: true, options: ['normal', 'holo', 'reverse_holo'], variantDefining: true),
                    // Print run — distinct market values per edition (1st Ed Charizard
                    // ≫ Shadowless ≫ Unlimited). Optional: only set where it applies.
                    Attr::make('edition', 'Edition', Type::Enum, searchable: true, indexed: true, options: ['unlimited', 'shadowless', 'first_edition'], variantDefining: true),
                    // Error/promo printing tag (black_dot_error, trainer_deck_a, …).
                    Attr::make('finish', 'Finish', Type::String, searchable: true, variantDefining: true),
                    Attr::make('illustrator', 'Illustrator', Type::String, searchable: true),
                    // Pokémon stat. Lorcana reuses `type` (Character/Action/Item/
                    // Location) and `illustrator` (artist) above.
                    Attr::make('hp', 'HP', Type::Integer),
                    Attr::make('type', 'Type', Type::String, searchable: true),

                    // Lorcana facets (product line `lorcana`). Optional at the
                    // vertical level — Pokémon never sets them, Lorcana never sets
                    // hp. Lorcana has no holo/edition axis, so `variant` is always
                    // 'normal' and alt-arts (Enchanted/Iconic) are distinct cards.
                    Attr::make('ink_color', 'Ink', Type::String, searchable: true, indexed: true),
                    Attr::make('franchise', 'Franchise', Type::String, searchable: true, indexed: true),
                    Attr::make('classifications', 'Classifications', Type::String, searchable: true),
                    Attr::make('cost', 'Ink Cost', Type::Integer),
                    Attr::make('lore', 'Lore', Type::Integer),
                    Attr::make('strength', 'Strength', Type::Integer),
                    Attr::make('willpower', 'Willpower', Type::Integer),
                    Attr::make('move_cost', 'Move Cost', Type::Integer),
                    Attr::make('inkable', 'Inkable', Type::Boolean),
                    Attr::make('abilities', 'Abilities', Type::Text),
                    Attr::make('body_text', 'Body Text', Type::Text),
                    Attr::make('flavor_text', 'Flavor Text', Type::Text),
                ],
                ItemType::Sealed->value => [
                    Attr::make('sealed_type', 'Sealed Type', Type::Enum, required: true, searchable: true, indexed: true, options: self::SEALED_TYPES),
                    Attr::make('language', 'Language', Type::Enum, required: true, searchable: true, indexed: true, options: self::LANGUAGES),
                    Attr::make('pack_count', 'Pack Count', Type::Integer),
                ],
            ],
        );
    }
}
