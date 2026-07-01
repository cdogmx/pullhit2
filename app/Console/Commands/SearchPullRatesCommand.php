<?php

namespace App\Console\Commands;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use App\Models\SetPullOdd;
use App\Support\RipOrKeep\PullRateResearcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Researches + stores Pokémon Scarlet & Violet-era booster pull rates via AI web
 * search (PullRateResearcher). One row per (set, hit rarity) with a citation.
 * Only "hit" rarities are priced — commons/uncommons carry no meaningful odds or
 * value. Idempotent (updateOrCreate); re-run with --force to refresh.
 */
class SearchPullRatesCommand extends Command
{
    protected $signature = 'pull-rates:search
        {--set= : research a single set by slug}
        {--limit=5 : max sets to research this run}
        {--force : re-research sets that already have odds}';

    protected $description = 'Research + store Pokémon SV booster pull rates via AI web search';

    /** The SV-era chase rarities worth pricing (exact catalog labels). */
    private const HIT_RARITIES = [
        'Double Rare', 'Ultra Rare', 'Illustration Rare',
        'Special Illustration Rare', 'Hyper Rare', 'ACE SPEC Rare',
    ];

    public function handle(PullRateResearcher $researcher): int
    {
        $sets = $this->targetSets();

        if ($sets->isEmpty()) {
            $this->info('No sets to research (all covered — use --force to refresh).');

            return self::SUCCESS;
        }

        foreach ($sets as $set) {
            $rarities = $this->hitRarities($set);

            if ($rarities === []) {
                $this->line("skip {$set->slug}: no hit rarities in catalog");

                continue;
            }

            $this->line("researching {$set->name} (".count($rarities).' rarities)…');

            try {
                $rates = $researcher->research($set->name, $rarities);
            } catch (Throwable $e) {
                $this->error("  failed: {$e->getMessage()}");

                continue;
            }

            $stored = 0;

            foreach ($rates as $r) {
                try {
                    SetPullOdd::updateOrCreate(
                        ['set_id' => $set->id, 'rarity' => $r['rarity']],
                        [
                            'per_pack_prob' => $r['per_pack_prob'],
                            'method' => 'ai_search',
                            'source' => $r['source'],
                            'note' => $r['note'],
                            'confidence' => $r['confidence'],
                        ],
                    );
                    $stored++;
                } catch (Throwable $e) {
                    $this->warn("  skipped {$r['rarity']}: {$e->getMessage()}");
                }
            }

            $this->info('  stored '.$stored.' rate(s)'.($rates === [] ? ' — none found' : ''));
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Set> */
    private function targetSets()
    {
        $pokemon = fn ($q) => $q->where('slug', 'pokemon');

        // An explicit --set is honored for ANY Pokémon set (its rarity structure
        // is the same across recent eras); the batch default stays SV-scoped.
        if ($slug = $this->option('set')) {
            return Set::query()->whereHas('productLine', $pokemon)
                ->where('slug', $slug)->get();
        }

        $query = Set::query()
            ->whereHas('productLine', $pokemon)
            ->where('series', 'Scarlet & Violet');

        if (! $this->option('force')) {
            $query->whereDoesntHave('pullOdds');
        }

        // Newest first — the sets people are deciding to rip/keep right now.
        return $query->orderByDesc('released_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }

    /**
     * The set's present hit rarities (intersect catalog rarities with the chase list).
     *
     * @return array<int, string>
     */
    private function hitRarities(Set $set): array
    {
        // Read rarity through the array cast (portable across MySQL/sqlite; the
        // `attributes` column shadows Eloquent's internal $attributes, so a plain
        // pluck won't do).
        $present = CatalogItem::query()
            ->where('set_id', $set->id)
            ->where('item_type', ItemType::Single->value)
            ->get(['attributes'])
            ->map(fn (CatalogItem $c) => $c->getAttribute('attributes')['rarity'] ?? null)
            ->filter()
            ->unique()
            ->all();

        return array_values(array_intersect(self::HIT_RARITIES, $present));
    }
}
