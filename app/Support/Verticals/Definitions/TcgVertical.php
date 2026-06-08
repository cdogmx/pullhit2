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

    public static function definition(): VerticalDefinition
    {
        return new VerticalDefinition(
            slug: 'tcg',
            name: 'Trading Card Games',
            attributes: [
                ItemType::Single->value => [
                    Attr::make('language', 'Language', Type::Enum, required: true, searchable: true, indexed: true, options: self::LANGUAGES),
                    Attr::make('rarity', 'Rarity', Type::String, required: true, searchable: true, indexed: true),
                    Attr::make('variant', 'Variant', Type::Enum, required: true, searchable: true, indexed: true, options: ['normal', 'holo', 'reverse_holo', '1st_edition', 'unlimited']),
                    Attr::make('finish', 'Finish', Type::String, searchable: true),
                    Attr::make('illustrator', 'Illustrator', Type::String, searchable: true),
                    Attr::make('hp', 'HP', Type::Integer),
                    Attr::make('type', 'Type', Type::String, searchable: true),
                ],
                ItemType::Sealed->value => [
                    Attr::make('sealed_type', 'Sealed Type', Type::Enum, required: true, searchable: true, indexed: true, options: ['booster_box', 'etb', 'booster_pack', 'booster_bundle', 'collection', 'bundle', 'tin']),
                    Attr::make('language', 'Language', Type::Enum, required: true, searchable: true, indexed: true, options: self::LANGUAGES),
                    Attr::make('pack_count', 'Pack Count', Type::Integer),
                ],
            ],
        );
    }
}
