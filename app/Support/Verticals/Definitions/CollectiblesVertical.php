<?php

namespace App\Support\Verticals\Definitions;

use App\Enums\ItemType;
use App\Support\Verticals\AttributeDefinition as Attr;
use App\Support\Verticals\AttributeType as Type;
use App\Support\Verticals\VerticalDefinition;

/**
 * The `collectibles` vertical — trading cards that are not a game.
 *
 * Topps Chrome, Panini Prizm, Upper Deck and the entertainment sets beside them.
 * They are collected and priced like TCG singles but have none of a game's
 * structure: no rarity ladder, no holo/reverse-holo axis, no set rotation. What
 * they have instead is PARALLELS — the same card printed in a dozen finishes,
 * each numbered and each worth something different.
 *
 * Its own vertical rather than a product line under `tcg`, because the schema is
 * genuinely different. The first two scans of this kind came back tagged
 * `variant: holo`, which is the TCG vocabulary being forced onto a chrome
 * refractor: a wrong answer that validated cleanly.
 *
 * Facets are deliberately few. They are additive later — a new optional facet
 * does not disturb an existing identity_hash — whereas removing or re-scoping
 * one silently re-hashes the catalog and duplicates every row it touches.
 */
final class CollectiblesVertical
{
    /** Kept identical to the TCG list so a shared importer never has to translate. */
    public const LANGUAGES = ['en', 'ja', 'ko', 'zh-CN', 'zh-TW', 'fr', 'de', 'it', 'es', 'pt'];

    /** Sealed shapes this category actually ships in. */
    public const SEALED_TYPES = [
        'hobby_box', 'blaster_box', 'hanger_box', 'mega_box', 'pack',
        'case', 'set', 'tin', 'other',
    ];

    public static function definition(): VerticalDefinition
    {
        return new VerticalDefinition(
            slug: 'collectibles',
            name: 'Collectibles',
            attributes: [
                ItemType::Single->value => [
                    // Required and identity-defining, exactly as in tcg: an EN and
                    // a JP printing are different cards and must never collide.
                    Attr::make('language', 'Language', Type::Enum, required: true, searchable: true, indexed: true, options: self::LANGUAGES, identityDefining: true),

                    // THE printing axis, and the reason this vertical exists.
                    // "Base", "Refractor", "Gold /50", "Superfractor" are one card
                    // in several printings at wildly different prices — the same
                    // relationship holo/reverse_holo has in tcg.
                    //
                    // Free text, not an enum: sets invent parallels every year, and
                    // a closed list would refuse to create a card nobody had
                    // enumerated yet rather than importing it. Normalise on the way
                    // in so "Refractor" and "refractor" are one printing.
                    Attr::make('parallel', 'Parallel', Type::String, searchable: true, indexed: true, variantDefining: true),

                    // How many were printed, when the card says. Descriptive only:
                    // it travels WITH the parallel ("Gold /50"), so making it
                    // identity-defining would split one printing in two whenever a
                    // source omitted the number.
                    Attr::make('print_run', 'Print run', Type::Integer),

                    // Who made it. Two companies print the same subject in the same
                    // year, so this is how a person tells them apart in a list.
                    Attr::make('manufacturer', 'Manufacturer', Type::String, searchable: true, indexed: true),

                    // Disney, Pixar, Marvel, a sports league. Mirrors the facet
                    // Lorcana already uses in tcg, so browse can group on it.
                    Attr::make('franchise', 'Franchise', Type::String, searchable: true, indexed: true),

                    // Both are separate products rather than a condition of one:
                    // an autographed card and its unsigned twin share a name and a
                    // number and are not remotely the same thing.
                    Attr::make('autograph', 'Autograph', Type::Boolean, searchable: true, indexed: true, identityDefining: true),
                    Attr::make('memorabilia', 'Memorabilia', Type::Boolean, searchable: true, indexed: true, identityDefining: true),

                    // Insert/rarity wording where a set uses one. Descriptive, for
                    // the same reason it is in tcg: sources disagree on the words.
                    Attr::make('rarity', 'Rarity', Type::String, searchable: true, indexed: true),
                ],

                ItemType::Sealed->value => [
                    Attr::make('language', 'Language', Type::Enum, required: true, searchable: true, indexed: true, options: self::LANGUAGES, identityDefining: true),
                    Attr::make('sealed_type', 'Sealed Type', Type::Enum, required: true, searchable: true, indexed: true, options: self::SEALED_TYPES, identityDefining: true),
                    Attr::make('manufacturer', 'Manufacturer', Type::String, searchable: true, indexed: true),
                    Attr::make('franchise', 'Franchise', Type::String, searchable: true, indexed: true),
                ],
            ],
        );
    }
}
