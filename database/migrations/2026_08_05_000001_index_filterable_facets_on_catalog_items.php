<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the facets people filter by indexable.
 *
 * They live in the JSON `attributes` column, so every filter compiled to
 * `json_unquote(json_extract(attributes, '$.rarity')) = ?` — an expression no
 * index can serve. EXPLAIN on a single rarity filter: type=ALL, key=NULL,
 * rows=60,189. Browse read the whole table to answer any facet question.
 *
 * This extends the "only `language` is generated" rule from the original
 * catalog_items migration (§13) to the rest of the filterable set. The
 * justification is the same one that earned language its exception — a facet the
 * catalog is queried BY needs an index — and the columns stay virtual, so they
 * cost index space rather than row width, and `attributes` remains the single
 * source of truth that the Vertical registry validates.
 *
 * `finish` and `stamp` are included even though `stamp` is currently empty:
 * they are the axes the pattern/stamp filters will read, and adding them now
 * avoids a second pass over the table later.
 */
return new class extends Migration
{
    /** Facet => column length. Kept generous; these are free-text in the registry. */
    private const FACETS = [
        'rarity' => 64,
        'variant' => 32,
        'edition' => 32,
        'finish' => 96,
        'stamp' => 64,
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (self::FACETS as $facet => $length) {
            if (Schema::hasColumn('catalog_items', $facet)) {
                continue;
            }

            // The expression is driver-specific: MySQL needs json_unquote to
            // return a plain string, sqlite's json_extract already does.
            $expression = match ($driver) {
                'mysql', 'mariadb' => "json_unquote(json_extract(`attributes`, '$.{$facet}'))",
                default => "json_extract(\"attributes\", '$.{$facet}')",
            };

            Schema::table('catalog_items', function (Blueprint $table) use ($facet, $length, $expression) {
                $table->string($facet, $length)->nullable()->virtualAs($expression)->index();
            });
        }

        // Filters arrive in combinations, and a browse is nearly always scoped to
        // a set or a product line first. These composites let the scope narrow the
        // rows before the facet is tested, instead of each single-column index
        // being used alone and intersected.
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->index(['set_id', 'rarity'], 'catalog_items_set_rarity_index');
            $table->index(['product_line_id', 'item_type', 'rarity'], 'catalog_items_line_type_rarity_index');
            $table->index(['product_line_id', 'item_type', 'variant'], 'catalog_items_line_type_variant_index');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex('catalog_items_set_rarity_index');
            $table->dropIndex('catalog_items_line_type_rarity_index');
            $table->dropIndex('catalog_items_line_type_variant_index');
        });

        foreach (array_keys(self::FACETS) as $facet) {
            if (! Schema::hasColumn('catalog_items', $facet)) {
                continue;
            }

            Schema::table('catalog_items', function (Blueprint $table) use ($facet) {
                $table->dropIndex([$facet]);
                $table->dropColumn($facet);
            });
        }
    }
};
