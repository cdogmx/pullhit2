<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Models\CatalogItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Record a sale by hand.
 *
 * The scraper cannot reach every sale. A colourway print is the clearest case:
 * one real listing reads "Ultra-Rare Blue Mew B/RGB Thirty Aniv Freshly Pulled
 * Clean" — no "Pokemon", no "30th Celebration" — so no keyword query built from
 * what we know about the card would return it, and it sold for $20,000.
 *
 * Three things keep a hand-entered sale honest:
 *
 * - It is keyed on the eBay listing id, the same key the sweep uses, so if the
 *   scraper ever does reach this sale it updates the row instead of counting it
 *   twice.
 * - raw.source says "manual", so it can always be told from scraped data and
 *   audited later.
 * - It clears the card's synthetic placeholder for that priced state, exactly as
 *   an ingested sale does — a real figure should retire the guess.
 *
 * It deliberately does NOT go through the comp classifier. The gates exist to
 * judge titles nobody vouched for; a sale typed in by hand has been vouched for.
 */
class AddSaleCommand extends Command
{
    protected $signature = 'valuation:add-sale
        {item : catalog item id}
        {price : sale price in dollars}
        {--listing= : the eBay listing id, so a later scrape updates rather than duplicates}
        {--title= : the listing title, for the audit trail}
        {--sold= : the date it sold (default: today)}
        {--condition=NM : NM, LP, MP, HP or DMG}
        {--execute : write it (otherwise report only)}';

    protected $description = 'Record a sale the scraper cannot reach';

    public function handle(RecomputeCatalogItem $recompute): int
    {
        $item = CatalogItem::with('set')->find($this->argument('item'));

        if (! $item) {
            $this->error("No catalog item {$this->argument('item')}.");

            return self::FAILURE;
        }

        $cents = (int) round((float) $this->argument('price') * 100);

        if ($cents <= 0) {
            $this->error('The price has to be a positive number of dollars.');

            return self::FAILURE;
        }

        $soldAt = $this->option('sold') ? Carbon::parse($this->option('sold')) : Carbon::now();
        $listing = $this->option('listing') ?: 'manual-'.$item->getKey().'-'.$soldAt->format('Ymd');
        $condition = strtoupper((string) $this->option('condition'));

        $this->table(['field', 'value'], [
            ['card', $item->display_name.' — '.($item->set?->name ?? 'no set')],
            ['price', '$'.number_format($cents / 100, 2)],
            ['condition', $condition],
            ['sold', $soldAt->toDayDateTimeString()],
            ['listing', $listing],
            ['title', $this->option('title') ?: '—'],
        ]);

        if (! $this->option('execute')) {
            $this->warn('Dry run. Pass --execute to record it.');

            return self::SUCCESS;
        }

        // A real figure retires the guess for this priced state, the same way an
        // ingested sale does.
        $item->saleObservations()
            ->where('is_synthetic', true)
            ->whereNull('grading_company_id')
            ->where('condition', $condition)
            ->delete();

        $item->saleObservations()->updateOrCreate(
            ['source_listing_id' => $listing, 'venue' => 'ebay'],
            [
                'condition' => $condition,
                'grading_company_id' => null,
                'grade' => null,
                'grade_label' => null,
                'price' => $cents,
                'currency' => 'USD',
                'observed_at' => $soldAt,
                'is_outlier' => false,
                'is_synthetic' => false,
                'raw' => [
                    'title' => $this->option('title'),
                    'url' => $this->option('listing') ? 'https://www.ebay.com/itm/'.$this->option('listing') : null,
                    'source' => 'manual',
                ],
            ],
        );

        $states = $recompute($item);

        $this->info("Recorded. Recomputed {$states} priced state(s) for {$item->display_name}.");

        return self::SUCCESS;
    }
}
