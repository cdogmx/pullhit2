<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PriceCharting per-card state: when we last pulled the product page (drives the
 * once-per-view lazy refresh + batch TTL) and the long-term monthly price series
 * parsed from its chart (VGPC.chart_data) — history older than eBay's sold view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->timestamp('pc_synced_at')->nullable()->after('ebay_refreshed_at');
            $table->json('pc_price_history')->nullable()->after('pc_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['pc_synced_at', 'pc_price_history']);
        });
    }
};
