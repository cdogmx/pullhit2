<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named collections — a user can own several (gated by tier). Every existing
 * holding moves into a per-user default "My Collection" so nothing is orphaned;
 * its public flag carries over from users.is_collection_public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
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

        Schema::table('collection_items', function (Blueprint $table) {
            $table->foreignId('collection_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        // Backfill: one default collection per user; assign their existing items.
        $now = now();
        foreach (DB::table('users')->select('id', 'is_collection_public')->cursor() as $user) {
            $collectionId = DB::table('collections')->insertGetId([
                'user_id' => $user->id,
                'name' => 'My Collection',
                'slug' => 'my-collection',
                'is_public' => (bool) ($user->is_collection_public ?? false),
                'is_default' => true,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('collection_items')->where('user_id', $user->id)->update(['collection_id' => $collectionId]);
        }
    }

    public function down(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_id');
        });

        Schema::dropIfExists('collections');
    }
};
