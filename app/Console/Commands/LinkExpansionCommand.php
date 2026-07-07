<?php

namespace App\Console\Commands;

use App\Models\Set;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Links sets that are the same expansion across languages by giving them a
 * shared `expansion_key` (used to surface a card's other-language printings).
 * The two sets have no shared code/name in our data, so this is a manual,
 * domain-knowledge pairing — e.g. JP "Ninja Spinner" <-> EN "Chaos Rising".
 *
 *   php artisan catalog:link-expansion ninja-spinner-ja chaos-rising
 */
class LinkExpansionCommand extends Command
{
    protected $signature = 'catalog:link-expansion {sets* : Set slugs (or ids) that are the same expansion} {--key= : Explicit expansion_key (defaults to a slug of the first set name)} {--unset : Clear the expansion_key on the given sets instead of linking}';

    protected $description = 'Give sets a shared expansion_key so a card links to its other-language printings';

    public function handle(): int
    {
        $refs = collect($this->argument('sets'));

        if (! $this->option('unset') && $refs->count() < 2) {
            $this->error('Pass at least two set slugs/ids to link (or --unset with one or more to clear).');

            return self::FAILURE;
        }

        $sets = $refs->map(function (string $ref) {
            $set = is_numeric($ref)
                ? Set::find($ref)
                : Set::where('slug', $ref)->first();

            if (! $set) {
                $this->error("No set found for \"{$ref}\".");
            }

            return $set;
        });

        if ($sets->contains(null)) {
            return self::FAILURE;
        }

        if ($this->option('unset')) {
            foreach ($sets as $set) {
                $set->update(['expansion_key' => null]);
                $this->line("  {$set->slug} [{$set->language}] — {$set->name}  →  (cleared)");
            }

            $this->info("Cleared expansion_key on {$sets->count()} set(s).");

            return self::SUCCESS;
        }

        $key = $this->option('key') ?: Str::slug($sets->first()->name);

        foreach ($sets as $set) {
            $set->update(['expansion_key' => $key]);
            $this->line("  {$set->slug} [{$set->language}] — {$set->name}  →  {$key}");
        }

        $this->info("Linked {$sets->count()} sets under expansion_key \"{$key}\".");

        return self::SUCCESS;
    }
}
