<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the eBay seller username on each sold comp so the valuation engine
 * can down-weight single-seller-dominated comps (a shill/wash signal visible
 * on public eBay data). Buyer identity is structurally unavailable on eBay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_observations', function (Blueprint $table) {
            $table->string('seller')->nullable()->after('source_listing_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_observations', function (Blueprint $table) {
            $table->dropColumn('seller');
        });
    }
};
