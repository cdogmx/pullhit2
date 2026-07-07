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
    protected $signature = 'catalog:link-expansion {sets* : Two-or-more set slugs (or ids) that are the same expansion} {--key= : Explicit expansion_key (defaults to a slug of the first set name)}';

    protected $description = 'Give sets a shared expansion_key so a card links to its other-language printings';

    public function handle(): int
    {
        $refs = collect($this->argument('sets'));

        if ($refs->count() < 2) {
            $this->error('Pass at least two set slugs/ids to link.');

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

        $key = $this->option('key') ?: Str::slug($sets->first()->name);

        foreach ($sets as $set) {
            $set->update(['expansion_key' => $key]);
            $this->line("  {$set->slug} [{$set->language}] — {$set->name}  →  {$key}");
        }

        $this->info("Linked {$sets->count()} sets under expansion_key \"{$key}\".");

        return self::SUCCESS;
    }
}
