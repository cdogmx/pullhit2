<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the single-seller concentration of the comps behind each snapshot so
 * the UI can flag values that rest on one dominant eBay seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_values', function (Blueprint $table) {
            $table->decimal('top_seller_share', 4, 3)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('market_values', function (Blueprint $table) {
            $table->dropColumn('top_seller_share');
        });
    }
};
