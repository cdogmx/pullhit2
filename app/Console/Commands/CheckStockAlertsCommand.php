<?php

namespace App\Console\Commands;

use App\Actions\Alerts\CheckStockAlerts;
use App\Enums\Retailer;
use App\Models\RetailerLink;
use App\Models\TrackedProduct;
use App\Support\Retail\RetailScraper;
use App\Support\Social\XClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Poll stock alerts and tweet retailers in stock at/below target.
 *
 * Default (scheduled): checks every due retailer link, tweeting on the rising
 * edge. Ad-hoc (--url + --retailer + --max): one-off check of a single link
 * with no DB row. Tweets only with --tweet.
 */
class CheckStockAlertsCommand extends Command
{
    protected $signature = 'stock:check-alerts
        {--url= : ad-hoc: check this single product URL}
        {--retailer= : ad-hoc retailer (amazon|walmart|target|bestbuy|costco|sams_club|pokemon_center)}
        {--max= : ad-hoc target price in dollars (required with --url)}
        {--tweet : ad-hoc: actually post the tweet if it qualifies}
        {--force : ignore each link\'s throttle window and check all active}
        {--dry : check + persist but never post tweets}';

    protected $description = 'Check stock alerts and tweet retailers in stock at/below target';

    public function handle(CheckStockAlerts $action): int
    {
        if ($this->option('url')) {
            return $this->adHoc();
        }

        $summary = $action((bool) $this->option('force'), (bool) $this->option('dry'));

        $this->line("checked: {$summary['checked']}  qualified: {$summary['qualified']}  tweeted: {$summary['tweeted']}  errors: {$summary['errors']}");

        return self::SUCCESS;
    }

    /** One-off check of a single retailer URL, no persistence. */
    private function adHoc(): int
    {
        $retailer = Retailer::tryFrom((string) $this->option('retailer'));
        $max = $this->option('max');

        if (! $retailer) {
            $this->error('--retailer must be one of: '.implode(', ', array_map(fn (Retailer $r) => $r->value, Retailer::cases())));

            return self::FAILURE;
        }

        if ($max === null || ! is_numeric($max)) {
            $this->error('--max=<dollars> is required with --url.');

            return self::FAILURE;
        }

        $url = (string) $this->option('url');
        $targetCents = (int) round((float) $max * 100);

        $product = new TrackedProduct(['target_price' => $targetCents, 'currency' => 'USD']);
        $link = new RetailerLink(['retailer' => $retailer->value, 'url' => $url]);
        $link->external_id = $retailer->externalIdFromUrl($url);
        $link->setRelation('product', $product);

        try {
            $snapshot = app(RetailScraper::class)->fetch($link);
        } catch (Throwable $e) {
            $this->error("Fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $price = $snapshot['price'];
        $qualifies = $snapshot['in_stock'] && $price !== null && $price <= $targetCents;

        $this->line('retailer: '.$retailer->label());
        $this->line('title:    '.($snapshot['title'] ?? '—'));
        $this->line('price:    '.($price === null ? '—' : '$'.number_format($price / 100, 2)));
        $this->line('stock:    '.($snapshot['stock'] ?? '—'));
        $this->line('in stock: '.($snapshot['in_stock'] ? 'yes' : 'no'));
        $this->line('target:   $'.number_format($targetCents / 100, 2));
        $this->line($qualifies ? '<info>QUALIFIES ✓</info>' : '<comment>does not qualify</comment>');

        if (! $qualifies) {
            return self::SUCCESS;
        }

        $text = app(CheckStockAlerts::class)->composeTweet($link, $snapshot);
        $this->newLine();
        $this->line('<info>Tweet preview:</info>');
        $this->line($text);

        if ($this->option('tweet')) {
            try {
                $id = app(XClient::class)->tweetWithImage($text, $snapshot['image'] ?? null);
                $this->info("Posted tweet {$id}");
            } catch (Throwable $e) {
                $this->error("Tweet failed: {$e->getMessage()}");

                return self::FAILURE;
            }
        } else {
            $this->comment('(--tweet not set; not posting)');
        }

        return self::SUCCESS;
    }
}
