<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sticky admin decisions for the broad eBay sweep, keyed by eBay listing id:
 * either reject the listing (never apply it again) or reassign it to the correct
 * card (always apply it there). The sweep consults this before matching, so a
 * one-time correction holds across every future re-pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_sweep_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('source_listing_id')->unique();
            $table->string('action');                       // reject | reassign
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_sweep_overrides');
    }
};
