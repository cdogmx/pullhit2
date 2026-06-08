<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product line within a vertical — TCG: Pokémon, MTG, One Piece;
 * Sports: Basketball, Football.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['vertical_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_lines');
    }
};
