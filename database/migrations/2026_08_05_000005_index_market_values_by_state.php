<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a query find every value in one priced state.
 *
 * The unique key on market_values leads with catalog_item_id, which answers
 * "this card's states" but not "every NM value" — so the price-inversion report,
 * which pairs each card's NM value against its graded ones, scanned all 175,022
 * rows and sorted them. Leading with state_key turns that side into a range
 * read, and carrying is_estimated lets the real-values-only filter come from the
 * index too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_values', function (Blueprint $table) {
            $table->index(
                ['state_key', 'is_estimated', 'catalog_item_id'],
                'market_values_state_estimated_item_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('market_values', function (Blueprint $table) {
            $table->dropIndex('market_values_state_estimated_item_index');
        });
    }
};
