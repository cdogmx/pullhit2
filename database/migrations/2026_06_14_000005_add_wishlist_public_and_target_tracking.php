<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public wishlists (shareable at /wishlist/{username}, reusing the existing
 * username) + target-price alert bookkeeping: `target_hit_at` records when a
 * card crossed at/below its target so the daily check alerts once, not every day
 * (cleared when the price recovers above target).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_wishlist_public')->default(false)->after('is_collection_public');
        });

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->timestamp('target_hit_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_wishlist_public'));
        Schema::table('wishlist_items', fn (Blueprint $table) => $table->dropColumn('target_hit_at'));
    }
};
