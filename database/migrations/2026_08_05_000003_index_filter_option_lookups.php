<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lists that populate the browse filter dropdowns.
 *
 * Building those lists was the slowest thing on the page — over five seconds
 * unscoped. Indexing the facets fixed the bulk of it, but two costs remained:
 * the facet DISTINCTs are always qualified by item_type, which is not a prefix
 * of any existing index, and the grader/grade lists scan market_values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // Browse is singles-only by default, so every facet list is
            // "distinct X where item_type = single" — covered end to end here.
            $table->index(['item_type', 'rarity'], 'catalog_items_type_rarity_index');
            $table->index(['item_type', 'variant'], 'catalog_items_type_variant_index');
            $table->index(['item_type', 'edition'], 'catalog_items_type_edition_index');
        });

        Schema::table('market_values', function (Blueprint $table) {
            // The grade list is a DISTINCT over 162k rows with no index to read.
            // grading_company_id already has one from its foreign key.
            $table->index('grade', 'market_values_grade_index');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex('catalog_items_type_rarity_index');
            $table->dropIndex('catalog_items_type_variant_index');
            $table->dropIndex('catalog_items_type_edition_index');
        });

        Schema::table('market_values', function (Blueprint $table) {
            $table->dropIndex('market_values_grade_index');
        });
    }
};
