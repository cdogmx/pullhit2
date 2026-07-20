<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "For sale" valuation alongside the sold-based one. Active asks (eBay Browse +
 * TCGplayer via TCGCSV) land in listing_observations; the derived lowest-realistic
 * ask and the sold+ask "combined" figure are stored per priced state on
 * market_values. Asks are ephemeral, so a refresh replaces a card's rows wholesale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('state_key');                 // NM, psa-10, … (as market_values)
            $table->string('condition')->nullable();
            $table->foreignId('grading_company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('grade', 3, 1)->nullable();
            $table->string('venue');                     // ebay | tcgplayer
            $table->unsignedInteger('price');            // asking price, cents
            $table->char('currency', 3)->default('USD');
            $table->string('source_listing_id')->nullable();
            $table->string('seller')->nullable();
            $table->string('url', 1024)->nullable();
            $table->dateTime('observed_at');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['catalog_item_id', 'state_key']);
        });

        Schema::table('market_values', function (Blueprint $table) {
            // Lowest realistic current ask + how many asks back it (null = none).
            $table->unsignedInteger('for_sale')->nullable()->after('trend_90d');
            $table->unsignedInteger('for_sale_n')->nullable()->after('for_sale');
            // Sold-anchored blend of median (sold) and for_sale.
            $table->unsignedInteger('combined')->nullable()->after('for_sale_n');
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dateTime('for_sale_refreshed_at')->nullable()->after('ebay_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', fn (Blueprint $t) => $t->dropColumn('for_sale_refreshed_at'));
        Schema::table('market_values', fn (Blueprint $t) => $t->dropColumn(['for_sale', 'for_sale_n', 'combined']));
        Schema::dropIfExists('listing_observations');
    }
};
