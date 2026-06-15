<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards a user wants but doesn't own. Simpler than collection_items — no
 * cost basis or quantity — with an optional target price so we can flag when the
 * current market value drops to where they'd buy. One row per (user, card).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('target_price')->nullable(); // cents
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
