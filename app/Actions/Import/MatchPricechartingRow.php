<?php

namespace App\Actions\Import;

use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Import\MatchResult;
use App\Support\Import\PricechartingRow;

/**
 * Resolve a parsed PriceCharting row to a catalog_item: narrow by language +
 * set name, then by collector number, name, and (if present) variant. Returns a
 * tiered result (matched / ambiguous / unmatched) with a reason for stats.
 * Pragmatic v1 — refine the fuzziness from real `collection:import-preview` runs.
 */
class MatchPricechartingRow
{
    public function __invoke(PricechartingRow $row): MatchResult
    {
        $target = self::norm($row->setName);
        if ($target === '') {
            return new MatchResult($row, null, 'unmatched', 'no_set_name');
        }

        $sets = Set::query()->where('language', $row->language)->get(['id', 'name', 'code']);
        if ($sets->isEmpty()) {
            return new MatchResult($row, null, 'unmatched', "no_set_for_lang:{$row->language}");
        }

        $setIds = $sets->filter(fn (Set $s) => self::norm($s->name) === $target
            || self::norm((string) $s->code) === $target
            || str_contains(self::norm($s->name), $target)
            || str_contains($target, self::norm($s->name)),
        )->pluck('id');

        if ($setIds->isEmpty()) {
            return new MatchResult($row, null, 'unmatched', 'no_set_match');
        }

        $items = CatalogItem::query()
            ->whereIn('set_id', $setIds)
            ->get(['id', 'name', 'number', 'attributes', 'set_id', 'base_key']);

        // Narrow by collector number (normalized: case-fold, strip punctuation + leading zeros).
        if ($row->number !== null) {
            $num = self::normNumber($row->number);
            $byNumber = $items->filter(fn (CatalogItem $i) => self::normNumber((string) $i->number) === $num);
            if ($byNumber->isNotEmpty()) {
                $items = $byNumber;
            }
        }

        // Narrow by name (substring either direction, normalized).
        $nameTarget = self::norm($row->name);
        if ($nameTarget !== '') {
            $byName = $items->filter(function (CatalogItem $i) use ($nameTarget) {
                $n = self::norm($i->name);

                return $n !== '' && (str_contains($n, $nameTarget) || str_contains($nameTarget, $n));
            });
            if ($byName->isNotEmpty()) {
                $items = $byName;
            }
        }

        // Prefer the matching printing when the row names a variant (e.g. reverse).
        if ($row->variant !== null) {
            $byVariant = $items->filter(fn (CatalogItem $i) => ($i->attributes['variant'] ?? null) === $row->variant);
            if ($byVariant->isNotEmpty()) {
                $items = $byVariant;
            }
        } elseif ($items->count() > 1) {
            // No variant named ⇒ the base (non-reverse) printing. PriceCharting
            // tags reverse holos as "[Reverse]", so an untagged row is the normal
            // printing — disambiguate the common normal-vs-reverse pair.
            $base = $items->filter(fn (CatalogItem $i) => in_array($i->attributes['variant'] ?? 'normal', ['normal', 'holo', null], true));
            if ($base->isNotEmpty()) {
                $items = $base;
            }
        }

        if ($items->isEmpty()) {
            return new MatchResult($row, null, 'unmatched', 'no_item');
        }

        if ($items->count() > 1) {
            return new MatchResult($row, $items->first(), 'ambiguous', 'multiple_items');
        }

        return new MatchResult($row, $items->first(), 'matched', 'ok');
    }

    private static function norm(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }

    private static function normNumber(string $s): string
    {
        $n = ltrim((string) preg_replace('/[^a-z0-9]+/i', '', strtolower($s)), '0');

        return $n === '' ? '0' : $n;
    }
}
