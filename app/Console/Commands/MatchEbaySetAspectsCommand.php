<?php

namespace App\Console\Commands;

use App\Models\Set;
use App\Support\Ebay\EbayTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Match our set names to eBay's, by reading eBay's own list of them.
 *
 * Asking eBay which set a card belongs to does not work: it matches keywords
 * loosely, so the aspect distribution for every 30th Celebration card is led by
 * "Celebrations" — a different set, for all six sample cards, by name, by number
 * and with the full fraction pinned. Picking the leader would have pinned a
 * whole set's comps to the wrong set.
 *
 * Reading the vocabulary works, because it is a closed list of 2,290 values and
 * the difference between the two namings is mostly decoration. eBay writes the
 * set code in front — "Swsh09: Brilliant Stars", "Sv08: Surging Sparks",
 * "Sm-Burning Shadows", "EX Dragon" — and once that is stripped the names are
 * the same string.
 *
 * What is left over is left over. "30th Celebration" is "30th Anniversary
 * Edition" to eBay and no amount of normalising gets from one to the other, so
 * it is reported for a person to decide rather than guessed at.
 */
class MatchEbaySetAspectsCommand extends Command
{
    protected $signature = 'ebay:match-set-aspects
        {--set= : only this set slug}
        {--force : re-match sets that already have an answer}
        {--execute : write the confident matches (otherwise report only)}';

    protected $description = "Match our sets to eBay's Set aspect vocabulary";

    public function handle(EbayTaxonomy $taxonomy): int
    {
        $category = (string) config('valuation.ebay.singles_category');
        $vocabulary = $taxonomy->aspectValues('Set', $category);

        if ($vocabulary === []) {
            $this->error('eBay returned no Set vocabulary for that category.');

            return self::FAILURE;
        }

        $this->info(number_format(count($vocabulary)).' set names from eBay.');

        // Normalised form => the values sharing it. A collision means two eBay
        // sets normalise alike, and neither can be chosen for us.
        $index = [];

        foreach ($vocabulary as $value) {
            $index[$this->normalise($value)][] = $value;
        }

        $sets = Set::query()
            ->when($this->option('set'), fn (Builder $q, $slug) => $q->where('slug', $slug))
            ->when(! $this->option('force'), fn (Builder $q) => $q->whereNull('ebay_set'))
            ->whereHas('catalogItems')
            ->orderByDesc('released_at')
            ->get();

        $matched = 0;
        $rows = [];

        foreach ($sets as $set) {
            $candidates = $index[$this->normalise($set->name)] ?? [];

            if (count($candidates) === 1) {
                $matched++;

                if ($this->option('execute')) {
                    $set->forceFill([
                        'ebay_set' => $candidates[0],
                        'ebay_set_learned_at' => now(),
                    ])->save();
                }

                if (mb_strtolower($candidates[0]) !== mb_strtolower($set->name)) {
                    $rows[] = [mb_substr($set->name, 0, 34), $candidates[0]];
                }

                continue;
            }

            $this->unmatched[] = [
                mb_substr($set->name, 0, 34),
                $candidates === [] ? 'no match' : count($candidates).' candidates: '.implode(' | ', $candidates),
            ];
        }

        if ($rows !== []) {
            $this->line('');
            $this->table(['our set', 'eBay calls it'], array_slice($rows, 0, 25));
        }

        $this->line('');
        $this->info("matched {$matched} of {$sets->count()} set(s)".
            ($this->option('execute') ? '' : ' (dry run — nothing written)'));

        if ($this->unmatched !== []) {
            $this->warn(count($this->unmatched).' left for a person to decide:');
            $this->table(['our set', 'why'], array_slice($this->unmatched, 0, 20));
            $this->line('Record one with: ebay:learn-set-aspect --set=<slug> --value="<eBay name>" --execute');
        }

        return self::SUCCESS;
    }

    /** @var array<int, array<int, string>> */
    private array $unmatched = [];

    /**
     * Both namings reduced to the part that is actually the set's name.
     *
     * eBay writes the code in front and we do not: "Swsh09: Brilliant Stars",
     * "Sv08: Surging Sparks", "Sm-Burning Shadows", "SV: Paldean Fates", "XY -
     * Steam Siege", "EX Dragon". Stripping the decoration leaves the same
     * string on both sides for most of the catalog.
     */
    private function normalise(string $name): string
    {
        $out = mb_strtolower(trim($name));

        // A leading set code, however it is punctuated.
        $out = (string) preg_replace('/^(swsh|sv|sm|smp|xy|bw|hgss|dp|ex|cp|op|st)\s*\d*[a-z]*\s*[:\-]\s*/u', '', $out);
        $out = (string) preg_replace('/^(ex|sm|xy)[\s\-]+/u', '', $out);

        // "Sword & Shield - Chilling Reign" and "Chilling Reign" are one set.
        $out = (string) preg_replace('/^(sword & shield|scarlet & violet|sun & moon)\s*-\s*/u', '', $out);

        $out = str_replace([' and ', '&', '’', "'"], [' ', ' ', '', ''], $out);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $out));
    }
}
