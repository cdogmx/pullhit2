<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved price race: which cards to race, and how to run the tape.
 *
 * Sources are kept as a spec rather than a resolved list of card ids, so a race
 * over a set keeps meaning "that set" as cards are added to it — the 30th
 * Celebration gained four cards by hand the day this was written. An explicit
 * card list is just another kind of source, which is what lets someone race
 * eight cards they picked against each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_races', function (Blueprint $table) {
            $table->id();
            // Null for the races we ship; set for one somebody built.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('sources');
            $table->json('options')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_races');
    }
};
