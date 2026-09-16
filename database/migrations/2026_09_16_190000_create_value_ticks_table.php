<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An intraday reading of what a card is worth.
 *
 * value_snapshots keeps one row per card per day, which is the right resolution
 * for a settled card and far too coarse for a set in its first fortnight, where
 * the whole market is being priced in the same afternoon.
 *
 * eBay dates its sold listings without a time — every one of the 1.2M comps we
 * hold lands at midnight — so no amount of scraping reconstructs an hourly sold
 * price. What genuinely moves within a day is our estimate as sales are found,
 * and the asking prices, which change constantly. That is what this records, and
 * the chart built on it has to say so: it is price discovery, not a tick tape.
 *
 * Deliberately narrow. Written only for sets currently featured, because a
 * quarter-hourly row for 71,000 cards is 6.8M rows a day to answer a question
 * nobody is asking about a card that last moved in March.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('state_key', 32);
            $table->unsignedBigInteger('median_cents')->nullable();
            $table->unsignedBigInteger('for_sale_cents')->nullable();
            $table->unsignedInteger('n_sales')->default(0);
            $table->timestamp('captured_at');
            $table->timestamps();

            // One reading per card/state/minute: a scheduler that fires twice
            // must not double the series.
            $table->unique(['catalog_item_id', 'state_key', 'captured_at'], 'value_ticks_reading');
            $table->index(['captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_ticks');
    }
};
