<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Support\Str;

/**
 * Build a Topps-style collectibles set from its published checklist and odds.
 *
 * Topps releases every product as two documents: a checklist (card number,
 * subject, property) and an odds sheet (the parallels, and how often each falls
 * per box type). Between them they describe the whole set, so this takes both as
 * one structured file and creates a row per PRINTING — the base card plus every
 * parallel of it.
 *
 * That is deliberately a lot of rows. 2026 Disney Chrome is 200 base subjects
 * against 29 base parallels alone, so the set runs to thousands. The alternative
 * — create base cards and let parallels appear as people scan them — leaves a
 * collector unable to log the Gold Refractor they are holding, which is the
 * thing this catalog is for.
 *
 * The insert name is stored as `rarity`. It is the closest the schema has to a
 * subset, and it is how these are actually spoken about: a card is "an Iconic
 * Moments" the way a Pokémon card is "a Holo Rare".
 */
class ImportToppsSet
{
    public function __construct(protected CreateCatalogItem $create) {}

    /**
     * @param  array<string, mixed>  $data  the checklist + odds, already parsed
     * @return array{set: string, cards: int, printings: int}
     */
    public function __invoke(array $data, bool $withParallels = true): array
    {
        $vertical = Vertical::firstOrCreate(
            ['slug' => 'collectibles'],
            ['name' => 'Collectibles'],
        );

        $line = ProductLine::firstOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => Str::slug($data['brand'])],
            ['name' => $data['brand']],
        );

        $set = Set::firstOrCreate(
            ['product_line_id' => $line->id, 'slug' => $data['set']['slug']],
            [
                'name' => $data['set']['name'],
                'language' => $data['set']['language'] ?? 'en',
                'released_at' => $data['set']['released_at'] ?? null,
            ],
        );

        $cards = 0;
        $printings = 0;

        foreach ($data['subsets'] ?? [] as $subset) {
            // The base printing is always created; a parallel only when asked
            // for. Null rather than "Base" so the base card carries no parallel
            // at all — an absent facet and one set to a placeholder hash
            // differently, and the placeholder would strand it.
            $treatments = [null];

            if ($withParallels) {
                foreach ($subset['parallels'] ?? [] as $parallel) {
                    $treatments[] = $parallel['name'];
                }
            }

            foreach ($subset['cards'] ?? [] as $card) {
                $cards++;

                foreach ($treatments as $parallel) {
                    $this->printing($vertical, $line, $set, $subset, $card, $parallel);
                    $printings++;
                }
            }
        }

        return ['set' => $set->name, 'cards' => $cards, 'printings' => $printings];
    }

    /**
     * One row: this card in this printing.
     *
     * @param  array<string, mixed>  $subset
     * @param  array<string, mixed>  $card
     */
    protected function printing(
        Vertical $vertical,
        ProductLine $line,
        Set $set,
        array $subset,
        array $card,
        ?string $parallel,
    ): void {
        $attributes = array_filter([
            'language' => $set->language ?: 'en',
            'parallel' => $parallel,
            'manufacturer' => $line->name,
            'franchise' => $card['franchise'] ?? null,
            // The insert this card belongs to. Absent on the base set, where
            // "Base" would be noise on every row.
            'rarity' => ($subset['key'] ?? 'base') === 'base' ? null : ($subset['name'] ?? null),
            // Identity-defining, so an autograph is its own card rather than a
            // printing of the unsigned one.
            'autograph' => ! empty($subset['autograph']) ?: null,
            'memorabilia' => ! empty($subset['memorabilia']) ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        ($this->create)(
            vertical: $vertical,
            productLine: $line,
            set: $set,
            itemType: ItemType::Single,
            name: $card['name'],
            number: (string) $card['number'],
            attributes: $attributes,
        );
    }
}
