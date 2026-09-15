<?php

namespace App\Console\Commands;

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\CardDisplayName;
use Illuminate\Console\Command;

/**
 * The 30th Celebration's RGB secret rares: one Mew, printed three times.
 *
 * YOSHIROTTEN's card 30C exists in red, blue and green — marked R/RGB, B/RGB and
 * G/RGB on the card face. Same name, same number, same artwork, three colourways
 * that trade as three different cards.
 *
 * They are modelled as three rows differing only by `finish`, which the TCG
 * vertical already declares variant-defining. That does three things at once:
 * each colour gets its own identity_hash, so its own price and its own comps;
 * all three share a base_key, so they group as printings of one card the way a
 * Reverse Holo groups with its Normal; and display_name renders "Mew (Red)"
 * without anyone typing it.
 *
 * The colour deliberately does NOT go in the name. A bracket on a card name is
 * our disambiguator leaking into seller vocabulary — it ends up in the eBay
 * search term, where no listing carries it, and it blinds the comp classifier's
 * sibling gate. `finish` is a facet the system already knows how to read.
 *
 * There is no feed to import these from, so they are written out here rather
 * than typed into the admin, which leaves no record of where they came from.
 */
class SeedRgbSecretsCommand extends Command
{
    protected $signature = 'catalog:seed-rgb-secrets
        {--set=30th-celebration : the set they belong to}
        {--execute : write the rows (otherwise report only)}';

    protected $description = 'Add the 30th Celebration RGB secret rares (Mew 30C, red/blue/green)';

    private const NAME = 'Mew';

    private const NUMBER = '30C';

    /** Face code => the finish we file it under. */
    private const COLOURS = [
        'R/RGB' => 'red_rgb',
        'B/RGB' => 'blue_rgb',
        'G/RGB' => 'green_rgb',
    ];

    private const ATTRIBUTES = [
        'language' => 'en',
        'variant' => 'holo',
        'rarity' => 'Rare Secret',
        'illustrator' => 'YOSHIROTTEN',
        'hp' => 60,
        'type' => 'Psychic',
    ];

    public function handle(CreateCatalogItem $create): int
    {
        $line = ProductLine::with('vertical')->where('slug', 'pokemon')->first();

        if (! $line) {
            $this->error('No "pokemon" product line.');

            return self::FAILURE;
        }

        $set = Set::where('product_line_id', $line->id)
            ->where('slug', $this->option('set'))
            ->first();

        if (! $set) {
            $this->error("No set with slug {$this->option('set')}.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $rows = [];
        $created = 0;

        foreach (self::COLOURS as $code => $finish) {
            // Matched on the finish, not the name: all three are called "Mew".
            $exists = CatalogItem::where('set_id', $set->id)
                ->where('item_type', ItemType::Single)
                ->where('number', self::NUMBER)
                ->where('attributes->finish', $finish)
                ->exists();

            $rows[] = [
                $code,
                CardDisplayName::for(self::NAME, ['finish' => $finish]),
                self::NUMBER,
                $exists ? 'exists' : 'CREATE',
            ];

            if ($exists) {
                continue;
            }

            $created++;

            if ($execute) {
                $create(
                    vertical: $line->vertical,
                    productLine: $line,
                    set: $set,
                    itemType: ItemType::Single,
                    name: self::NAME,
                    number: self::NUMBER,
                    attributes: self::ATTRIBUTES + ['finish' => $finish],
                );
            }
        }

        $this->table(['face code', 'card', 'number', ''], $rows);

        if (! $execute) {
            $this->warn("Dry run — {$created} row(s) would be created. Pass --execute.");

            return self::SUCCESS;
        }

        $this->info("Created {$created} row(s) in {$set->name}.");

        // These carry no tcgplayer_product_id, so a later TCGCSV import that
        // publishes them under any other name hashes differently and lands a
        // second copy beside them. Said out loud because the First Partners
        // promos did exactly that.
        if ($created > 0) {
            $this->line('<comment>No TCGplayer ids: set external_ids if these are ever published upstream.</comment>');
        }

        return self::SUCCESS;
    }
}
