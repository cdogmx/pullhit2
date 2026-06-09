<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields supporting real eBay sold-comp ingestion (Oxylabs) and lazy,
 * popularity-tiered refresh:
 *  - sale_observations.is_synthetic  — true for the seeded placeholder comps,
 *    false for real (eBay) comps; lets a card's synthetic data be replaced on
 *    its first real pull.
 *  - market_values.is_estimated      — true when the state's comps are all
 *    synthetic (UI shows an "Estimated" badge).
 *  - catalog_items popularity/last_viewed_at/ebay_refreshed_at — drive the
 *    on-view, TTL-by-popularity refresh decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_observations', function (Blueprint $table) {
            $table->boolean('is_synthetic')->default(false)->after('is_outlier');
        });

        Schema::table('market_values', function (Blueprint $table) {
            $table->boolean('is_estimated')->default(false)->after('confidence');
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->unsignedInteger('popularity')->default(0)->after('external_ids');
            $table->dateTime('last_viewed_at')->nullable()->after('popularity');
            $table->dateTime('ebay_refreshed_at')->nullable()->after('last_viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sale_observations', fn (Blueprint $t) => $t->dropColumn('is_synthetic'));
        Schema::table('market_values', fn (Blueprint $t) => $t->dropColumn('is_estimated'));
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['popularity', 'last_viewed_at', 'ebay_refreshed_at']);
        });
    }
};
