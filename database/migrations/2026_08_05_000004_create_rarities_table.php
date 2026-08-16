<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display rules for the rarity filter.
 *
 * Rarity reaches us as whatever each source calls it — 66 distinct strings, some
 * of them abbreviations ("SR", "UC"), some untranslated ("Kagayaku", "Art
 * Rare"), and two that mean nothing at all ("None" and "Unknown", 4,105 rows
 * between them). Sorted alphabetically and shown raw, that is not a filter
 * anyone can use.
 *
 * This does NOT replace the value. `catalog_items.rarity` stays the indexed
 * string every query filters on, and each source string keeps its own row here —
 * a Japanese "Art Rare" is not folded into "Illustration Rare", it just gets a
 * readable label and a place in the order. The table only decides how the option
 * is presented and where it sits from common to chase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rarities', function (Blueprint $table) {
            $table->id();
            // The raw string as it appears in catalog_items.rarity.
            $table->string('value', 64)->unique();
            $table->string('label', 64);
            // Common → chase. Ties are fine; the label breaks them.
            $table->unsignedSmallInteger('sort_order')->default(500);
            // "None"/"Unknown" carry no meaning — kept out of the dropdown while
            // the rows they sit on stay searchable.
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->index(['is_hidden', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rarities');
    }
};
