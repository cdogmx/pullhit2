<?php

namespace App\Console\Commands;

use App\Support\Ebay\EbayBrowseClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Verifies the eBay Browse API credentials by running a live listings search.
 * Run after setting production (or sandbox) keys + EBAY_CAMPAIGN_ID for affiliate links.
 */
class EbayTestCommand extends Command
{
    protected $signature = 'ebay:test {query=Charizard ex 223 Surging Sparks}';

    protected $description = 'Verify the eBay Browse API credentials with a live listings search';

    public function handle(EbayBrowseClient $client): int
    {
        if (! $client->configured()) {
            $this->error('eBay Browse keys not set — add EBAY_PRODUCTION_CLIENT_ID/SECRET (or EBAY_CLIENT_ID/SECRET), then `config:clear`.');

            return self::FAILURE;
        }

        $this->line('Env: '.config('services.ebay.env').' · campaign: '.(config('services.ebay.campaign_id') ?: 'NOT SET'));

        $query = (string) $this->argument('query');
        $this->info("Searching eBay Browse for: {$query}");

        $listings = $client->search($query, 5);

        if (empty($listings)) {
            $this->warn('Connected, but 0 listings returned. Check the keys are Production (not Sandbox), or try another query.');

            return self::FAILURE;
        }

        foreach ($listings as $l) {
            $affiliate = Str::contains($l['url'], ['campid', 'mkcid', 'mkrid']) ? 'affiliate ✓' : 'plain (no EPN)';
            $this->line(sprintf('  $%s  %s  [%s]', number_format($l['price_cents'] / 100, 2), Str::limit($l['title'], 50), $affiliate));
        }

        $campaign = config('services.ebay.campaign_id');
        $this->newLine();
        $this->info(count($listings).' listings returned. EPN campaign: '.($campaign ? $campaign : 'NOT SET (Buy links won\'t earn commission)'));

        return self::SUCCESS;
    }
}
