<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-window (24-hour and 7-day) trends alongside the 30/90-day ones.
 * Freshly-released cards have no 30/90-day prior window yet, so those trends are
 * null for weeks — but their prices move fastest right after release. The 24h and
 * 7-day changes fill that gap so the card page can show direction from day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_values', function (Blueprint $table) {
            $table->decimal('trend_1d', 6, 2)->nullable()->after('half_life_days');
            $table->decimal('trend_7d', 6, 2)->nullable()->after('trend_1d');
        });
    }

    public function down(): void
    {
        Schema::table('market_values', function (Blueprint $table) {
            $table->dropColumn(['trend_1d', 'trend_7d']);
        });
    }
};
