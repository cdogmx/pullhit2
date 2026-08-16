<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the PriceCharting long-term series out of catalog_items.
 *
 * It averaged ~1 KB per row and reached 15 KB, which made it 67 MB of a 227 MB
 * table — 30% of the weight — while being present on only 13% of rows. Browse,
 * search and every list endpoint select the model without a column list, so all
 * of that travelled with every page of results to be thrown away: it is read in
 * four places, all of them single-card detail or compare views.
 *
 * Its own table keeps catalog_items narrow for the scans that matter and loads
 * the series only where it is actually rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_item_price_histories', function (Blueprint $table) {
            $table->id();
            // One series per card; unique so the relation is a genuine hasOne.
            $table->foreignId('catalog_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('history');
            $table->timestamps();
        });

        if (! Schema::hasColumn('catalog_items', 'pc_price_history')) {
            return;
        }

        // Copy before dropping. Only the rows that actually carry a series.
        // CURRENT_TIMESTAMP rather than NOW() so this runs on sqlite too — the
        // test suite migrates a fresh in-memory database.
        DB::statement('
            INSERT INTO catalog_item_price_histories (catalog_item_id, history, created_at, updated_at)
            SELECT id, pc_price_history, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM catalog_items
            WHERE pc_price_history IS NOT NULL
        ');

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('pc_price_history');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('catalog_items', 'pc_price_history')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->json('pc_price_history')->nullable();
            });

            // A correlated subquery rather than UPDATE…JOIN, which sqlite lacks.
            DB::statement('
                UPDATE catalog_items
                SET pc_price_history = (
                    SELECT history FROM catalog_item_price_histories h
                    WHERE h.catalog_item_id = catalog_items.id
                )
            ');
        }

        Schema::dropIfExists('catalog_item_price_histories');
    }
};
