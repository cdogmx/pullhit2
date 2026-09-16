<?php

namespace App\Console\Commands;

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use Illuminate\Console\Command;

/**
 * Add a single card the feed does not have yet.
 *
 * TCGCSV lags TCGplayer. The 30th Celebration's Mewtwo ex 151/128 has a product
 * page and a price, and the group feed we import from carries neither it nor
 * three of its neighbours — so the set sits on the site with four holes in it
 * and no way to close them by re-importing.
 *
 * The TCGplayer product id is the important argument. Without it the row has
 * nothing tying it to the feed, and the day the feed catches up the importer
 * hashes it differently and lands a second copy beside this one — which is how
 * the First Partners promos ended up doubled.
 */
class AddCardCommand extends Command
{
    protected $signature = 'catalog:add-card
        {set : the set slug}
        {name : the card name}
        {number : the collector number}
        {--rarity= : the rarity, as our vocabulary spells it}
        {--variant=holo : normal, holo or reverse_holo}
        {--product= : the TCGplayer product id, so a later import updates rather than duplicates}
        {--execute : write it (otherwise report only)}';

    protected $description = 'Add one card by hand, for a set the feed has not caught up with';

    public function handle(CreateCatalogItem $create): int
    {
        $set = Set::with('productLine.vertical')->where('slug', $this->argument('set'))->first();

        if (! $set) {
            $this->error("No set with slug {$this->argument('set')}.");

            return self::FAILURE;
        }

        $number = (string) $this->argument('number');
        $name = (string) $this->argument('name');

        $existing = CatalogItem::where('set_id', $set->id)
            ->where('number', $number)
            ->where('name', $name)
            ->first();

        if ($existing) {
            $this->warn("Already present as #{$existing->id} — {$existing->display_name}.");

            return self::SUCCESS;
        }

        $attributes = array_filter([
            'language' => $set->language ?? 'en',
            'variant' => $this->option('variant'),
            'rarity' => $this->option('rarity'),
        ]);

        $this->table(['field', 'value'], [
            ['set', $set->name],
            ['name', $name],
            ['number', $number],
            ['rarity', $this->option('rarity') ?: '—'],
            ['variant', $this->option('variant')],
            ['tcgplayer', $this->option('product') ?: '— (a later import will duplicate this row)'],
        ]);

        if (! $this->option('execute')) {
            $this->warn('Dry run. Pass --execute to add it.');

            return self::SUCCESS;
        }

        $item = $create(
            vertical: $set->productLine->vertical,
            productLine: $set->productLine,
            set: $set,
            itemType: ItemType::Single,
            name: $name,
            number: $number,
            attributes: $attributes,
            externalIds: array_filter(['tcgplayer_product_id' => $this->option('product')]),
        );

        $this->info("Added #{$item->id} — {$item->display_name} at {$item->path()}");

        return self::SUCCESS;
    }
}
