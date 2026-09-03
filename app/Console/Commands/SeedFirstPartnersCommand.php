<?php

namespace App\Console\Commands;

use App\Actions\Catalog\AddSealedProduct;
use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Console\Command;

/**
 * The First Partner Illustration Collection, declared.
 *
 * TCGplayer sells this line as sealed product only — its group carries the
 * collection, the case and the code card, but not one of the twenty-seven promo
 * cards inside them. There is no feed to import the cards from, so they are
 * written out here rather than typed into the admin one at a time, which is how
 * Series 1 and 2 arrived and why nothing recorded where they came from.
 *
 * Three series of nine, one region each, in national-dex order: Series 1 took
 * generations 1/4/7, Series 2 took 2/5/8, Series 3 takes 3/6/9 — Hoenn, Kalos
 * and Paldea. The numbering runs unbroken across all three (MEP 037–063), which
 * is why each series declares its own numbers rather than counting from one.
 *
 * Nothing existing is rewritten: a row already in the catalog is reported and
 * skipped. Series 1 and 2 have been trading for months and carry hand-uploaded
 * images and real sales, and re-running this must not disturb them.
 */
class SeedFirstPartnersCommand extends Command
{
    protected $signature = 'catalog:seed-first-partners
        {--series= : only this series (1, 2 or 3)}
        {--execute : write the rows (otherwise report only)}';

    protected $description = 'Add the First Partner Illustration Collection sets, promos and sealed products';

    /**
     * Each series: the set it lives in, its nine promos in collector-number
     * order, and the sealed collection they come in.
     */
    private const SERIES = [
        1 => [
            'set' => [
                'slug' => 'first-partners', 'name' => 'Series 1', 'code' => 'PFP',
                'released_at' => '2026-05-01',
            ],
            // Kanto, Sinnoh, Alola — grass, fire, water within each region.
            'cards' => [
                37 => 'Bulbasaur', 38 => 'Charmander', 39 => 'Squirtle',
                40 => 'Turtwig', 41 => 'Chimchar', 42 => 'Piplup',
                43 => 'Rowlet', 44 => 'Litten', 45 => 'Popplio',
            ],
            'sealed' => [
                'name' => 'First Partner Illustration Collection - Series 1',
                'msrp_cents' => 1499, 'released_at' => '2026-05-01',
                'tcgplayer_product_id' => '673436',
            ],
        ],
        2 => [
            'set' => [
                'slug' => 'first-partners-2', 'name' => 'Series 2', 'code' => 'PFP2',
                'released_at' => '2026-06-19',
            ],
            // Johto, Unova, Galar.
            'cards' => [
                46 => 'Chikorita', 47 => 'Cyndaquil', 48 => 'Totodile',
                49 => 'Snivy', 50 => 'Tepig', 51 => 'Oshawott',
                52 => 'Grookey', 53 => 'Scorbunny', 54 => 'Sobble',
            ],
            'sealed' => [
                'name' => 'First Partner Illustration Collection - Series 2',
                'msrp_cents' => 1499, 'released_at' => '2026-06-19',
                'tcgplayer_product_id' => '688712',
            ],
        ],
        3 => [
            'set' => [
                'slug' => 'first-partners-3', 'name' => 'Series 3', 'code' => 'PFP3',
                'released_at' => '2026-08-07',
            ],
            // Hoenn, Kalos, Paldea.
            'cards' => [
                55 => 'Treecko', 56 => 'Torchic', 57 => 'Mudkip',
                58 => 'Chespin', 59 => 'Fennekin', 60 => 'Froakie',
                61 => 'Sprigatito', 62 => 'Fuecoco', 63 => 'Quaxly',
            ],
            'sealed' => [
                'name' => 'First Partner Illustration Collection - Series 3',
                'msrp_cents' => 1499, 'released_at' => '2026-08-07',
                'tcgplayer_product_id' => '695400',
            ],
        ],
    ];

    /** Shared by every set in the line. */
    private const SET_DEFAULTS = ['series' => 'First Partners', 'language' => 'en'];

    /**
     * Every card in the line is the same kind of printing: a holo promo. What
     * varies is which three of the nine a collection happens to contain.
     */
    private const CARD_ATTRIBUTES = ['language' => 'en', 'variant' => 'holo', 'rarity' => 'Promo'];

    public function handle(CreateCatalogItem $create, AddSealedProduct $addSealed): int
    {
        $line = ProductLine::with('vertical')->where('slug', 'pokemon')->first();

        if (! $line) {
            $this->error('No "pokemon" product line.');

            return self::FAILURE;
        }

        $only = $this->option('series');

        if ($only !== null && ! array_key_exists((int) $only, self::SERIES)) {
            $this->error("There is no series {$only}.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $rows = [];
        $created = 0;

        foreach (self::SERIES as $number => $series) {
            if ($only !== null && (int) $only !== $number) {
                continue;
            }

            $set = Set::where('product_line_id', $line->id)
                ->where('slug', $series['set']['slug'])
                ->first();

            if ($set) {
                $rows[] = [$number, 'set', $series['set']['name'], 'exists'];
            } else {
                $rows[] = [$number, 'set', $series['set']['name'], 'CREATE'];
                $created++;

                if ($execute) {
                    // Created directly rather than through CreateSet, which would
                    // derive "series-3" from the name. The slug is the card URL
                    // (/pokemon/first-partners-3/treecko-55) and has to continue
                    // the two sets already published.
                    $set = Set::create(array_merge(self::SET_DEFAULTS, $series['set'], [
                        'product_line_id' => $line->id,
                    ]));
                }
            }

            foreach ($series['cards'] as $cardNumber => $name) {
                $exists = $set && CatalogItem::where('set_id', $set->id)
                    ->where('item_type', ItemType::Single)
                    ->where('number', (string) $cardNumber)
                    ->exists();

                $rows[] = [$number, 'card', "{$cardNumber}  {$name}", $exists ? 'exists' : 'CREATE'];

                if ($exists) {
                    continue;
                }

                $created++;

                if ($execute && $set) {
                    $create(
                        vertical: $line->vertical,
                        productLine: $line,
                        set: $set,
                        itemType: ItemType::Single,
                        name: $name,
                        number: (string) $cardNumber,
                        attributes: self::CARD_ATTRIBUTES,
                    );
                }
            }

            $sealed = $series['sealed'];
            $found = $set && CatalogItem::where('set_id', $set->id)
                ->where('item_type', ItemType::Sealed)
                ->where('name', $sealed['name'])
                ->exists();

            $rows[] = [$number, 'sealed', $sealed['name'], $found ? 'exists' : 'CREATE'];

            if ($found) {
                continue;
            }

            $created++;

            if ($execute && $set) {
                $item = $addSealed($set, [
                    'name' => $sealed['name'],
                    'sealed_type' => 'collection',
                    'language' => 'en',
                    // The promo pack plus the two boosters packed beside it.
                    'pack_count' => '3',
                    'msrp_cents' => $sealed['msrp_cents'],
                    'released_at' => $sealed['released_at'],
                ]);

                // Recorded so the sealed price has something upstream to follow —
                // AddSealedProduct does not carry external ids itself.
                $item->forceFill([
                    'external_ids' => array_merge($item->external_ids ?? [], [
                        'tcgplayer_product_id' => $sealed['tcgplayer_product_id'],
                    ]),
                ])->save();
            }
        }

        $this->table(['Series', 'Kind', 'Item', 'Action'], $rows);

        if (! $execute) {
            $this->warn("Dry run — {$created} rows would be created. Re-run with --execute.");

            return self::SUCCESS;
        }

        $this->info("Created {$created} rows.");

        return self::SUCCESS;
    }
}
