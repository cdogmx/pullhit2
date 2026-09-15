<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Catalog\Subsets;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Move cards out of the set they were imported into and into the set they
 * actually belong to.
 *
 * A promo run printed for an expansion arrives from TCGplayer inside a general
 * promo group — the 30th Celebration's eighteen promos landed in "Mega Evolution
 * Promo" among ninety-odd unrelated cards, because that is the group TCGplayer
 * sells them under. Nobody browsing the 30th Celebration finds them there.
 *
 * Filing them into "30th Celebration Promos" puts them where they are looked
 * for: Subsets nests a set named "<Parent> <Suffix>" under its parent in browse,
 * so the promos become a tile on the expansion instead of a needle in a promo
 * pile. The destination set is created if it does not exist, inheriting the
 * source set's brand, language and series.
 *
 * Every card keeps its old URL working — CatalogItem records a slug alias when
 * its set changes, so /{brand}/{old-set}/{card} redirects rather than 404s.
 */
class RefileCardsCommand extends Command
{
    protected $signature = 'catalog:refile
        {from : slug of the set the cards are in now}
        {into : name of the set they should be in (created if missing)}
        {--matching= : only cards whose name is LIKE this (e.g. "%30th Celebration%")}
        {--execute : move the rows (otherwise report only)}';

    protected $description = 'Refile cards from one set into another, keeping their old URLs alive';

    public function handle(): int
    {
        $source = Set::where('slug', $this->argument('from'))->first();

        if (! $source) {
            $this->error("No set with slug {$this->argument('from')}.");

            return self::FAILURE;
        }

        $cards = CatalogItem::query()
            ->where('set_id', $source->id)
            ->when($this->option('matching'), fn ($q, $like) => $q->where('name', 'like', $like))
            ->orderByRaw('CAST(number AS UNSIGNED), number')
            ->get();

        if ($cards->isEmpty()) {
            $this->warn('Nothing matched — no cards moved.');

            return self::SUCCESS;
        }

        $name = $this->argument('into');
        $target = $this->target($source, $name);

        if ($target && $target->id === $source->id) {
            $this->error('The destination set is the source set.');

            return self::FAILURE;
        }

        $this->line("{$cards->count()} card(s) from <options=bold>{$source->name}</> → <options=bold>{$name}</>");

        foreach ($cards as $card) {
            $this->line(sprintf('  %-6s %s', $card->number ?? '—', $card->name));
        }

        if (! $this->option('execute')) {
            $this->newLine();
            $this->warn('Dry run. Pass --execute to move them.');

            return self::SUCCESS;
        }

        $target ??= $this->create($source, $name);

        foreach ($cards as $card) {
            $card->set_id = $target->id;
            $card->save();
        }

        $this->newLine();
        $this->info("Moved {$cards->count()} card(s) into {$target->name} (/{$source->productLine->slug}/{$target->slug}).");

        return self::SUCCESS;
    }

    /** The destination set, if it already exists in the source's brand and language. */
    private function target(Set $source, string $name): ?Set
    {
        return Set::where('product_line_id', $source->product_line_id)
            ->where('language', $source->language)
            ->where('name', $name)
            ->first();
    }

    /**
     * Create the destination alongside the source.
     *
     * Where the name reads as a subset — "30th Celebration Promos" — the parent
     * expansion is the better template than the promo pile the cards are being
     * pulled out of: same code, same series, and the release date of the set the
     * run was printed for rather than of the group it was sold in.
     *
     * It deliberately gets no external_ids, so re-importing the source group
     * still targets the source set.
     */
    private function create(Set $source, string $name): Set
    {
        $template = $this->parent($source, $name) ?? $source;

        return Set::create([
            'product_line_id' => $source->product_line_id,
            'slug' => Str::slug($name),
            'name' => $name,
            'code' => $template->code,
            'language' => $source->language,
            'series' => $template->series,
            'set_family' => $name,
            'released_at' => $template->released_at,
        ]);
    }

    /** The expansion a subset name nests under, if we hold it. */
    private function parent(Set $source, string $name): ?Set
    {
        [$parent] = Subsets::split($name);

        return $parent === null ? null : $this->target($source, $parent);
    }
}
