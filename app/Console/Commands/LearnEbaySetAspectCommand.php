<?php

namespace App\Console\Commands;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbayBrowseClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ask eBay what it calls each of our sets.
 *
 * eBay files every card in "CCG Individual Cards" under a structured Set aspect,
 * and pinning it is worth more than any keyword: the 30th Celebration's Sylveon
 * returns 126 listings on keywords and 22 with the Set pinned, the other 104
 * being a different Sylveon from Celebrations — a 2021 set sharing almost every
 * word with ours.
 *
 * The names differ, and no rule derives one from the other: we say "30th
 * Celebration", eBay says "30th Anniversary Edition". So it is learned.
 *
 * It cannot be learned reliably, and this command does not pretend otherwise.
 * eBay matches keywords loosely, so the distribution for any 30th Celebration
 * card is led by "Celebrations" — a 2021 set — for every card tried, by number,
 * by name, and with the fraction pinned. Both candidates appear for all six
 * sample cards. There is no signal here to separate them.
 *
 * So it proposes and a person decides. What it will not do is guess: writing
 * "Celebrations" as the eBay name for the 30th Celebration would pin every one
 * of its comps to the wrong set, which is worse than having no mapping at all.
 * --value writes a decision; without it the command only ever reports.
 */
class LearnEbaySetAspectCommand extends Command
{
    protected $signature = 'ebay:learn-set-aspect
        {--set= : one set slug, instead of every set missing an answer}
        {--value= : write this value for --set, instead of asking eBay}
        {--cards=6 : how many of its cards to ask about}
        {--limit=40 : how many sets to do in this run}
        {--force : re-learn sets that already have an answer}
        {--execute : write what is learned (otherwise report only)}';

    protected $description = 'Learn the eBay "Set" aspect value for our sets';

    /** A value has to lead by this much to be trusted over the runner-up. */
    private const MARGIN = 1.5;

    public function handle(EbayBrowseClient $browse): int
    {
        if (! $browse->configured()) {
            $this->error('eBay Browse is not configured.');

            return self::FAILURE;
        }

        if ($value = $this->option('value')) {
            return $this->write($value);
        }

        $sets = Set::query()
            ->when($this->option('set'), fn (Builder $q, $slug) => $q->where('slug', $slug))
            ->when(! $this->option('force') && ! $this->option('set'),
                fn (Builder $q) => $q->whereNull('ebay_set'))
            ->whereHas('catalogItems')
            ->orderByDesc('released_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($sets->isEmpty()) {
            $this->info('Nothing to learn.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($sets as $set) {
            [$value, $score, $runnerUp] = $this->learn($browse, $set);

            $rows[] = [
                mb_substr($set->name, 0, 30),
                $value ?? '—',
                $value ? "{$score} card(s)" : 'no agreement',
                $runnerUp ?? '',
            ];

            if ($value && $this->option('execute')) {
                $set->forceFill([
                    'ebay_set' => $value,
                    'ebay_set_learned_at' => now(),
                ])->save();
            }
        }

        $this->table(['our set', 'eBay calls it', 'agreement', 'runner-up'], $rows);

        if (! $this->option('execute')) {
            $this->warn('Dry run. Pass --execute to write these.');
        }

        return self::SUCCESS;
    }

    /** Record a decision somebody made by looking. */
    private function write(string $value): int
    {
        if (! $this->option('set')) {
            $this->error('--value needs --set: it writes one set at a time, on purpose.');

            return self::FAILURE;
        }

        $set = Set::where('slug', $this->option('set'))->first();

        if (! $set) {
            $this->error("No set with slug {$this->option('set')}.");

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->warn("Would record {$set->name} as \"{$value}\". Pass --execute.");

            return self::SUCCESS;
        }

        $set->forceFill(['ebay_set' => $value, 'ebay_set_learned_at' => now()])->save();
        $this->info("{$set->name} is \"{$value}\" to eBay.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?string, 1: int, 2: ?string}
     */
    private function learn(EbayBrowseClient $browse, Set $set): array
    {
        // The most-looked-at cards: they have the most listings, so their
        // aspect distributions are the least noisy.
        $cards = CatalogItem::query()
            ->where('set_id', $set->id)
            ->where('item_type', ItemType::Single)
            ->orderByDesc('popularity')
            ->limit(max(2, (int) $this->option('cards')))
            ->get();

        if ($cards->count() < 2) {
            return [null, 0, null];
        }

        $seen = [];

        foreach ($cards as $card) {
            $values = $browse->setAspectValues(
                CardSearchTerms::browseQuery($card),
                (string) config('valuation.ebay.singles_category'),
            );

            // One vote per card, not per listing. A set that happens to hold a
            // thousand listings for one shared name would otherwise decide it.
            foreach (array_keys($values) as $value) {
                $seen[$value] = ($seen[$value] ?? 0) + 1;
            }
        }

        if ($seen === []) {
            return [null, 0, null];
        }

        arsort($seen);
        $values = array_keys($seen);
        $top = $values[0];
        $topScore = $seen[$top];
        $second = $values[1] ?? null;
        $secondScore = $second ? $seen[$second] : 0;

        // Agreement has to be real: a value seen for one card out of six is a
        // coincidence, and a tie is not an answer.
        $confident = $topScore >= 2
            && $topScore >= $cards->count() / 2
            && ($secondScore === 0 || $topScore >= $secondScore * self::MARGIN);

        return [
            $confident ? $top : null,
            $topScore,
            $second ? "{$second} ({$secondScore})" : null,
        ];
    }
}
