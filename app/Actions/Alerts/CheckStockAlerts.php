<?php

namespace App\Actions\Alerts;

use App\Models\RetailerLink;
use App\Support\Retail\RetailScraper;
use App\Support\Social\XClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Poll due retailer links and tweet the ones that just became in stock at/below
 * their product's target price. One tweet per retailer, on the rising edge
 * (the prior check didn't qualify), with a cooldown to avoid flapping spam.
 */
class CheckStockAlerts
{
    /** Don't tweet the same link more than once within this window. */
    private const TWEET_COOLDOWN_MINUTES = 360;

    public function __construct(
        private readonly RetailScraper $scraper,
        private readonly XClient $x,
    ) {}

    /**
     * @return array{checked: int, qualified: int, tweeted: int, errors: int}
     */
    public function __invoke(bool $force = false, bool $dryRun = false): array
    {
        $links = RetailerLink::query()
            ->with(['product.catalogItem'])
            ->when(! $force, fn ($q) => $q->due())
            ->when($force, fn ($q) => $q->where('is_active', true)->whereHas('product', fn ($p) => $p->where('is_active', true)))
            ->get();

        $summary = ['checked' => 0, 'qualified' => 0, 'tweeted' => 0, 'errors' => 0];

        foreach ($links as $link) {
            $r = $this->evaluate($link, $dryRun);
            $summary['checked']++;
            $summary['qualified'] += $r['qualified'] ? 1 : 0;
            $summary['tweeted'] += $r['tweeted'] ? 1 : 0;
            $summary['errors'] += $r['error'] ? 1 : 0;
        }

        return $summary;
    }

    /**
     * @return array{qualified: bool, tweeted: bool, error: bool, snapshot: ?array<string, mixed>}
     */
    public function evaluate(RetailerLink $link, bool $dryRun = false): array
    {
        try {
            $snapshot = $this->scraper->fetch($link);
        } catch (Throwable $e) {
            $link->forceFill([
                'last_checked_at' => Carbon::now(),
                'last_error' => mb_substr($e->getMessage(), 0, 250),
            ])->save();

            Log::warning('Stock alert check failed', [
                'retailer' => $link->retailer->value,
                'url' => $link->url,
                'message' => $e->getMessage(),
            ]);

            return ['qualified' => false, 'tweeted' => false, 'error' => true, 'snapshot' => null];
        }

        $target = $link->product->target_price;
        $price = $snapshot['price'];
        $qualifies = $snapshot['in_stock'] && $price !== null && $price <= $target;

        $tweeted = false;
        $tweetId = null;

        if ($qualifies && ! $link->last_qualified && ! $dryRun && ! $this->withinCooldown($link)) {
            try {
                $tweetId = $this->x->tweetWithImage(
                    $this->composeTweet($link, $snapshot),
                    $link->product->preferredImage() ?: ($snapshot['image'] ?? null),
                );
                $tweeted = true;
            } catch (Throwable $e) {
                $snapshot['tweet_error'] = $e->getMessage();
                Log::error('Stock alert tweet failed', [
                    'retailer' => $link->retailer->value,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $link->forceFill([
            'last_checked_at' => Carbon::now(),
            'last_price' => $price,
            'last_in_stock' => $snapshot['in_stock'],
            'last_status' => $snapshot['stock'] ? mb_substr($snapshot['stock'], 0, 250) : null,
            'last_title' => $snapshot['title'] ? mb_substr($snapshot['title'], 0, 250) : null,
            'last_image' => $snapshot['image'] ?? null,
            'last_qualified' => $qualifies,
            'last_error' => $snapshot['tweet_error'] ?? null,
        ]);

        if ($tweeted) {
            $link->last_tweeted_at = Carbon::now();
            $link->last_tweet_id = $tweetId;
        }

        $link->save();

        return ['qualified' => $qualifies, 'tweeted' => $tweeted, 'error' => false, 'snapshot' => $snapshot];
    }

    private function withinCooldown(RetailerLink $link): bool
    {
        return $link->last_tweeted_at !== null
            && $link->last_tweeted_at->copy()->addMinutes(self::TWEET_COOLDOWN_MINUTES)->isFuture();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function composeTweet(RetailerLink $link, array $snapshot): string
    {
        $headline = $link->product->headline() ?: ($snapshot['title'] ?? 'This item');
        $price = $this->money($snapshot['price'], $link->product->currency);
        $store = $link->retailer->label();
        $url = $link->url;

        // Keep the headline short so the whole thing fits 280 chars (a link is 23 via t.co).
        $headline = mb_strlen($headline) > 120 ? mb_substr($headline, 0, 117).'…' : $headline;

        return "🚨 {$headline} in stock at {$store} for {$price}\n{$url}";
    }

    private function money(?int $cents, string $currency): string
    {
        if ($cents === null) {
            return '—';
        }

        $symbol = match ($currency) {
            'USD', 'CAD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            default => '',
        };

        return $symbol.number_format($cents / 100, 2).($symbol === '' ? ' '.$currency : '');
    }
}
