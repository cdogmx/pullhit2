<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named wishlists — a user can own several (gated by tier). Existing wishlist
 * items move into a per-user default wishlist; the old (user, card) uniqueness
 * becomes (wishlist, card) so the same card can live in more than one wishlist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        // Give the user_id FK its own index so the composite unique can be
        // dropped (MySQL won't drop an index a foreign key still relies on).
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->index('user_id', 'wishlist_items_user_idx');
        });
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'catalog_item_id']);
        });
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->foreignId('wishlist_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        // Backfill: one default wishlist per user; assign their existing items.
        $now = now();
        foreach (DB::table('users')->select('id', 'is_wishlist_public')->cursor() as $user) {
            $wishlistId = DB::table('wishlists')->insertGetId([
                'user_id' => $user->id,
                'name' => 'My Wishlist',
                'slug' => 'my-wishlist',
                'is_public' => (bool) ($user->is_wishlist_public ?? false),
                'is_default' => true,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('wishlist_items')->where('user_id', $user->id)->update(['wishlist_id' => $wishlistId]);
        }

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->unique(['wishlist_id', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropUnique(['wishlist_id', 'catalog_item_id']);
            $table->dropConstrainedForeignId('wishlist_id');
            $table->dropIndex('wishlist_items_user_idx');
            $table->unique(['user_id', 'catalog_item_id']);
        });

        Schema::dropIfExists('wishlists');
    }
};
