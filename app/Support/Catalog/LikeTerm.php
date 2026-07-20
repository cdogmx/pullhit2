<?php

namespace App\Support\Catalog;

/**
 * Sanitise free-text before it becomes a `LIKE '%term%'` pattern. The `%` and `_`
 * wildcards never carry meaning in a card/set/number search — but left in, a
 * stray `%` turns the pattern into `LIKE '%%%'`, which matches every row and
 * forces a full-table scan (a user typing "100%" or a lone "%" could scan the
 * whole 69k-row catalog). We strip them rather than escape them, which is both
 * driver-agnostic (SQLite has no default LIKE escape char, MySQL does) and the
 * behaviour a search box wants anyway: "100%" searches for "100".
 */
class LikeTerm
{
    /** Strip LIKE metacharacters (and the escape char itself) from a term. */
    public static function clean(string $term): string
    {
        return str_replace(['\\', '%', '_'], '', $term);
    }
}
