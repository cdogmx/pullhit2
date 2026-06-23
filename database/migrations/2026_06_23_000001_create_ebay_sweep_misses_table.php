<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuning log for the broad eBay sweep: every parsed sold listing we could NOT
 * confidently apply to a card (no match, ambiguous, low score, or rejected by
 * the comp classifier). Lets us measure match quality and tighten the resolver
 * without ever writing a bad sale to a card's value. One row per eBay listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_sweep_misses', function (Blueprint $table) {
            $table->id();
            $table->string('search_label')->index();
            $table->string('source_listing_id')->unique();
            $table->string('title');
            $table->unsignedInteger('price')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->string('parsed_number')->nullable();
            $table->string('reason')->index();              // no_number | unmatched | ambiguous | low_score | classify_rejected
            $table->foreignId('best_catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->float('best_score')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_sweep_misses');
    }
};
