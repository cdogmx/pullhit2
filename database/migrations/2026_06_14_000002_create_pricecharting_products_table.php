<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw reference rows from the PriceCharting Legendary price-guide CSV, parsed
 * into our shape. The reconciliation diffs these against catalog_items. One row
 * per PriceCharting product (a single printing/edition/variant or sealed item).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricecharting_products', function (Blueprint $table) {
            $table->id();
            $table->string('pc_id')->unique();          // PriceCharting product id
            $table->string('console_name');             // "Pokemon Base Set"
            $table->string('product_name');             // "Charizard [1st Edition] #4"

            // Parsed identity.
            $table->string('language', 8)->default('en');
            $table->string('set_name')->nullable();     // "Base Set" (console minus line/lang)
            $table->foreignId('set_id')->nullable()->index(); // resolved catalog set
            $table->string('card_name')->nullable();    // "Charizard"
            $table->string('number')->nullable();       // "4"
            $table->string('edition')->nullable();      // unlimited|shadowless|first_edition
            $table->string('variant')->nullable();      // reverse_holo|holo
            $table->string('finish')->nullable();       // error/promo tag (black_dot_error…)
            $table->boolean('is_sealed')->default(false);
            $table->string('tcg_id')->nullable()->index();

            // Current values (cents). Grade mapping per the Prices-API docs.
            $table->unsignedBigInteger('price_ungraded')->nullable();  // loose-price
            $table->unsignedBigInteger('price_cib')->nullable();       // cib-price
            $table->unsignedBigInteger('price_grade8')->nullable();    // new-price
            $table->unsignedBigInteger('price_grade9')->nullable();    // graded-price
            $table->unsignedBigInteger('price_grade95')->nullable();   // box-only-price
            $table->unsignedBigInteger('price_psa10')->nullable();     // manual-only-price
            $table->unsignedBigInteger('price_bgs10')->nullable();     // bgs-10-price
            $table->unsignedInteger('sales_volume')->nullable();
            $table->date('release_date')->nullable();

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['set_id', 'number']);
            $table->index(['console_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricecharting_products');
    }
};
