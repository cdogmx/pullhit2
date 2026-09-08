<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Support\Ebay\EbayHtmlParser;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is eBay letting us read sold listings right now, and what is it doing instead?
 *
 * This exists because the same two symptoms have now had two different causes.
 * In July 2026 sold pages arrived complete and our parser had stopped reading
 * them; in September 2026 the pages stopped arriving at all — any of
 * LH_Sold/LH_Complete bounced to eBay's captcha splash or a sign-in wall while
 * the same keywords unfiltered came back whole. From the caller both look like
 * "no comps", and telling them apart by hand each time is how a parser bug got
 * diagnosed as a block.
 *
 * So --probe fetches one sold page and one active page and reports what actually
 * came back: bytes, title, listing-card count, and where the URL ended up. An
 * active page that parses while the sold page does not is a gate; both failing
 * is a transport or credential problem; a sold page full of cards that parses to
 * nothing is our parser, not eBay.
 */
class EbaySoldStatusCommand extends Command
{
    protected $signature = 'ebay:sold-status
        {--probe : spend two Oxylabs calls to test sold and active side by side}
        {--reset : close the breaker so the next fetch retries immediately}';

    protected $description = 'Report whether eBay sold search is reachable, and why it is not';

    public function handle(EbaySoldSource $source, OxylabsClient $oxylabs): int
    {
        if ($this->option('reset')) {
            $source->reset();
            $this->info('Breaker closed — the next fetch will try eBay again.');
        }

        $this->line('<comment>Breaker</comment>');
        $this->line('  sold search gated : '.($source->isDown() ? '<fg=red>yes, not spending</>' : '<fg=green>no</>'));
        $this->line('  consecutive blocks: '.$source->consecutiveBlocks()
            .' (trips at '.config('valuation.ebay.breaker.threshold').')');
        $this->line('  cooldown          : '.config('valuation.ebay.breaker.cooldown_minutes').' min');

        $this->line('<comment>Budget today</comment>');
        $this->line('  ebay: '.number_format($oxylabs->spent(OxylabsClient::BUDGET_EBAY))
            .' of '.number_format($oxylabs->cap(OxylabsClient::BUDGET_EBAY)).' spent');

        $this->line('<comment>Freshness</comment>');
        $newest = DB::table('sale_observations')->where('venue', 'ebay')->max('observed_at');
        $this->line('  newest eBay sale observed: '.($newest ?: 'never'));
        $this->line('  items refreshed in last 24h: '.number_format(
            CatalogItem::where('ebay_refreshed_at', '>=', now()->subDay())->count()
        ));

        if (! $this->option('probe')) {
            $this->newLine();
            $this->comment('Add --probe to test eBay live (costs two Oxylabs calls).');

            return self::SUCCESS;
        }

        // Probe with a card people actually sell. A long-tail item returns an
        // error or an empty page on its own merits and tells us nothing about
        // whether eBay is gating us.
        $item = CatalogItem::query()
            ->where('item_type', 'single')
            ->whereNotNull('number')
            ->withCount('saleObservations')
            ->orderByDesc('sale_observations_count')
            ->first();

        if (! $item) {
            $this->error('No card to probe with.');

            return self::FAILURE;
        }

        $soldUrl = $source->soldSearchUrl($item);
        $activeUrl = $this->withoutSoldFilters($soldUrl);

        $this->newLine();
        $this->line('<comment>Live probe</comment> ('.$item->name.' #'.$item->number.')');

        $rows = [];

        foreach (['sold' => $soldUrl, 'active' => $activeUrl] as $label => $url) {
            $rows[] = $this->probe($label, $url, $oxylabs);
        }

        $this->table(['Search', 'Bytes', 'Cards', 'Parsed', 'Title / outcome'], $rows);

        [$sold, $active] = $rows;

        $this->newLine();

        if ($sold[2] === '0' && $active[2] !== '0') {
            $this->error('eBay is gating SOLD searches specifically — active reads fine.');
            $this->line('  Scraped sold comps cannot be recovered by retrying or by a parser fix.');
        } elseif ($sold[2] !== '0' && $sold[3] === '0') {
            $this->error('The sold page arrived with listings but parsed to nothing — this is our parser.');
            $this->line('  Compare against tests/Fixtures/ebay-sold-search.html.');
        } elseif ($active[2] === '0') {
            $this->error('Neither search returned listings — check Oxylabs credentials, budget and geo.');
        } else {
            $this->info('Sold search is reachable and parsing.');
        }

        return self::SUCCESS;
    }

    /**
     * The same search with the completed/sold filters removed. If this reads and
     * the sold one does not, the filter is what is gated, not our access.
     */
    private function withoutSoldFilters(string $url): string
    {
        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $params);
        unset($params['LH_Sold'], $params['LH_Complete']);

        return $base.'?'.http_build_query($params);
    }

    /**
     * One fetch, reported as what actually came back rather than pass/fail.
     *
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function probe(string $label, string $url, OxylabsClient $oxylabs): array
    {
        try {
            $html = $oxylabs->fetchHtml($url, config('valuation.ebay.geo', 'United States'),
                budget: OxylabsClient::BUDGET_EBAY);
        } catch (Throwable $e) {
            return [$label, '—', '0', '0', class_basename($e).': '.$e->getMessage()];
        }

        if ($html === '') {
            // Oxylabs delivering an empty body is itself the signature of the
            // render dying against a challenge page.
            return [$label, '0', '0', '0', '<fg=red>empty response (render blocked)</>'];
        }

        preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m);
        $title = trim($m[1] ?? '(no title)');
        $cards = substr_count($html, 's-card');
        $parsed = count(EbayHtmlParser::parse($html));

        foreach (['splashui/captcha' => 'captcha splash', 'Security Measure' => 'captcha splash',
            'Sign in or Register' => 'sign-in wall'] as $needle => $verdict) {
            if (str_contains($html, $needle)) {
                $title = "<fg=red>{$verdict}</> — ".$title;
                break;
            }
        }

        return [$label, number_format(strlen($html)), (string) $cards, (string) $parsed, mb_strimwidth($title, 0, 52, '…')];
    }
}
