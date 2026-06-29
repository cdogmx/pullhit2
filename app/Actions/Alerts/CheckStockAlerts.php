<?php

namespace App\Actions\Alerts;

use App\Models\StockAlert;
use App\Support\Amazon\AmazonProductClient;
use App\Support\Social\XClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Poll due stock alerts and tweet the ones that just became in stock at/below
 * their target price. "Just became" = rising edge: we only tweet when the prior
 * evaluation did NOT qualify, so a product that stays in stock isn't re-tweeted
 * every tick. A cooldown guards against flapping (in/out/in) spam.
 */
class CheckStockAlerts
{
    /** Don't tweet the same alert more than once within this window. */
    private const TWEET_COOLDOWN_MINUTES = 360;

    public function __construct(
        private readonly AmazonProductClient $amazon,
        private readonly XClient $x,
    ) {}

    /**
     * Evaluate every due alert.
     *
     * @return array{checked: int, qualified: int, tweeted: int, errors: int}
     */
    public function __invoke(bool $force = false, bool $dryRun = false): array
    {
        $alerts = StockAlert::query()
            ->when(! $force, fn ($q) => $q->due())
            ->when($force, fn ($q) => $q->where('is_active', true))
            ->get();

        $summary = ['checked' => 0, 'qualified' => 0, 'tweeted' => 0, 'errors' => 0];

        foreach ($alerts as $alert) {
            $result = $this->evaluate($alert, $dryRun);
            $summary['checked']++;
            $summary['qualified'] += $result['qualified'] ? 1 : 0;
            $summary['tweeted'] += $result['tweeted'] ? 1 : 0;
            $summary['errors'] += $result['error'] ? 1 : 0;
        }

        return $summary;
    }

    /**
     * Check one alert, persist its state, and tweet on a fresh qualifying edge.
     *
     * @return array{qualified: bool, tweeted: bool, error: bool, snapshot: ?array<string, mixed>}
     */
    public function evaluate(StockAlert $alert, bool $dryRun = false): array
    {
        try {
            $snapshot = $this->amazon->fetch($alert->asin, $alert->domain, $alert->geo_location);
        } catch (Throwable $e) {
            $alert->forceFill([
                'last_checked_at' => Carbon::now(),
                'last_error' => mb_substr($e->getMessage(), 0, 250),
            ])->save();

            Log::warning('Stock alert check failed', ['asin' => $alert->asin, 'message' => $e->getMessage()]);

            return ['qualified' => false, 'tweeted' => false, 'error' => true, 'snapshot' => null];
        }

        $price = $snapshot['price'];
        $qualifies = $snapshot['in_stock']
            && $price !== null
            && $price <= $alert->target_price;

        $wasQualified = $alert->last_qualified;

        $tweeted = false;
        $tweetId = null;

        if ($qualifies && ! $wasQualified && ! $dryRun && $this->withinCooldown($alert) === false) {
            try {
                $tweetId = $this->x->tweet($this->composeTweet($alert, $snapshot));
                $tweeted = true;
            } catch (Throwable $e) {
                $snapshot['tweet_error'] = $e->getMessage();
                Log::error('Stock alert tweet failed', ['asin' => $alert->asin, 'message' => $e->getMessage()]);
            }
        }

        $alert->forceFill([
            'last_checked_at' => Carbon::now(),
            'last_price' => $price,
            'last_in_stock' => $snapshot['in_stock'],
            'last_status' => $snapshot['stock'] ? mb_substr($snapshot['stock'], 0, 250) : null,
            'last_title' => $snapshot['title'] ? mb_substr($snapshot['title'], 0, 250) : null,
            'last_qualified' => $qualifies,
            'last_error' => $snapshot['tweet_error'] ?? null,
        ]);

        if ($tweeted) {
            $alert->last_tweeted_at = Carbon::now();
            $alert->last_tweet_id = $tweetId;
        }

        $alert->save();

        return ['qualified' => $qualifies, 'tweeted' => $tweeted, 'error' => false, 'snapshot' => $snapshot];
    }

    private function withinCooldown(StockAlert $alert): bool
    {
        return $alert->last_tweeted_at !== null
            && $alert->last_tweeted_at->copy()->addMinutes(self::TWEET_COOLDOWN_MINUTES)->isFuture();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function composeTweet(StockAlert $alert, array $snapshot): string
    {
        $title = $snapshot['title'] ?? $alert->label ?? 'This item';
        $price = $this->money($snapshot['price'], $alert->currency);
        $target = $this->money($alert->target_price, $alert->currency);
        $url = $alert->productUrl();

        // Keep the title short so the whole thing comfortably fits 280 chars
        // (an Amazon link counts as 23 via t.co).
        $title = mb_strlen($title) > 120 ? mb_substr($title, 0, 117).'…' : $title;

        return "🚨 In stock! {$title} — {$price} (target {$target})\n{$url}";
    }

    private function money(?int $cents, string $currency): string
    {
        if ($cents === null) {
            return '—';
        }

        $symbol = $currency === 'USD' ? '$' : ($currency === 'GBP' ? '£' : ($currency === 'EUR' ? '€' : ''));

        return $symbol.number_format($cents / 100, 2).($symbol === '' ? ' '.$currency : '');
    }
}
