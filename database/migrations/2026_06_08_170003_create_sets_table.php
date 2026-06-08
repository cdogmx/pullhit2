<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A release/set/product within a product line. TCG sets are language-specific
 * (EN "151" ≠ JP "sv2a"); `series`/`set_family` group cross-language equivalents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_line_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('code')->nullable();
            // Language is a first-class facet of a set (TCG sets are language-specific).
            $table->string('language')->nullable()->index();
            $table->string('series')->nullable();
            $table->string('set_family')->nullable()->index();
            $table->date('released_at')->nullable();
            // {tcgplayer_group_id, ptcgio_set_id, scryfall_set, ...} for dedup/joins.
            $table->json('external_ids')->nullable();
            $table->timestamps();

            $table->unique(['product_line_id', 'slug']);
            $table->index(['product_line_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sets');
    }
};
