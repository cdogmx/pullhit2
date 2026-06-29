<?php

namespace App\Console\Commands;

use App\Actions\Alerts\CheckStockAlerts;
use App\Models\StockAlert;
use App\Support\Amazon\AmazonProductClient;
use App\Support\Social\XClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Poll Amazon stock alerts and tweet the ones in stock at/below target.
 *
 * Default run (scheduled): checks every due alert, tweeting on the rising edge.
 * Ad-hoc (--asin + --max): one-off check of a single ASIN with no DB row — used
 * to sanity-check a product/price. Tweets only with --tweet.
 */
class CheckStockAlertsCommand extends Command
{
    protected $signature = 'stock:check-alerts
        {--asin= : ad-hoc: check this single ASIN instead of the saved alerts}
        {--max= : ad-hoc target price in dollars (required with --asin)}
        {--domain=com : ad-hoc Amazon domain}
        {--geo= : ad-hoc Oxylabs geo_location (an Amazon delivery ZIP, optional)}
        {--tweet : ad-hoc: actually post the tweet if it qualifies}
        {--force : ignore each alert\'s throttle window and check all active}
        {--dry : check + persist but never post tweets}';

    protected $description = 'Check Amazon stock alerts and tweet in-stock-at-target hits';

    public function handle(CheckStockAlerts $action): int
    {
        if ($this->option('asin')) {
            return $this->adHoc();
        }

        $summary = $action((bool) $this->option('force'), (bool) $this->option('dry'));

        $this->line("checked: {$summary['checked']}  qualified: {$summary['qualified']}  tweeted: {$summary['tweeted']}  errors: {$summary['errors']}");

        return self::SUCCESS;
    }

    /** One-off check of a single ASIN, no persistence. */
    private function adHoc(): int
    {
        $max = $this->option('max');

        if ($max === null || ! is_numeric($max)) {
            $this->error('--max=<dollars> is required with --asin.');

            return self::FAILURE;
        }

        $asin = (string) $this->option('asin');
        $targetCents = (int) round((float) $max * 100);

        try {
            $snapshot = app(AmazonProductClient::class)->fetch(
                $asin,
                (string) $this->option('domain'),
                $this->option('geo') ?: null,
            );
        } catch (Throwable $e) {
            $this->error("Fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $price = $snapshot['price'];
        $qualifies = $snapshot['in_stock'] && $price !== null && $price <= $targetCents;

        $this->line("title:    ".($snapshot['title'] ?? '—'));
        $this->line("price:    ".($price === null ? '—' : '$'.number_format($price / 100, 2)));
        $this->line("stock:    ".($snapshot['stock'] ?? '—'));
        $this->line("in stock: ".($snapshot['in_stock'] ? 'yes' : 'no'));
        $this->line("target:   $".number_format($targetCents / 100, 2));
        $this->line($qualifies ? '<info>QUALIFIES ✓</info>' : '<comment>does not qualify</comment>');

        if (! $qualifies) {
            return self::SUCCESS;
        }

        // Build the tweet text via a transient alert so the ad-hoc preview
        // matches exactly what the scheduled run would post.
        $alert = new StockAlert([
            'asin' => $asin,
            'domain' => (string) $this->option('domain'),
            'target_price' => $targetCents,
            'currency' => $snapshot['currency'] ?? 'USD',
        ]);

        $text = app(CheckStockAlerts::class)->composeTweet($alert, $snapshot);
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
