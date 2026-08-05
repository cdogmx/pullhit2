<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slugs a card used to live at. The card page is /{brand}/{set}/{card-slug} and
 * the slug is derived from the card's name and collector number, so correcting
 * either changes the URL and silently 404s every existing link to it.
 *
 * Keyed by set rather than by card so a lookup needs only the URL's own parts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_item_slug_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->timestamps();

            // One owner per old URL. A slug freed by one card and later taken by
            // another would otherwise redirect to the wrong card.
            $table->unique(['set_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_slug_aliases');
    }
};
