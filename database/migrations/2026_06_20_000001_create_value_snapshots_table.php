<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily value-over-time series for a card's headline priced state — written by
 * `valuation:snapshot` for eligible (higher-rarity/valued + owned/wishlisted)
 * cards only. One row per (card, state, day). Powers the card-page trend chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('state_key'); // e.g. 'NM', 'SEALED'
            $table->integer('median_cents');
            $table->unsignedInteger('n_sales')->default(0);
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->boolean('is_estimated')->default(false);
            $table->date('captured_on');
            $table->timestamps();

            $table->unique(['catalog_item_id', 'state_key', 'captured_on']);
            $table->index(['catalog_item_id', 'state_key', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_snapshots');
    }
};
