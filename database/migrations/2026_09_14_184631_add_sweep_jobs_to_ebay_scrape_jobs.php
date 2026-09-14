<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agent's queue carried one kind of work: fetch one card's sold search.
 *
 * The broad sweep is the other kind — one page of "pokemon psa 10" holds a few
 * hundred sales across as many cards, and which cards is only known after the
 * titles are resolved. So a sweep job belongs to no card, and needs the search's
 * label instead to throttle it and to tag what it stores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebay_scrape_jobs', function (Blueprint $table) {
            // 'card' or 'sweep'. Defaulted so every existing row keeps its meaning.
            $table->string('kind', 12)->default('card')->after('id');

            // Which configured search this is, for the per-search interval and
            // so stored sales carry the same tag the Oxylabs sweep gave them.
            $table->string('label', 64)->nullable()->after('url');

            $table->index(['kind', 'label', 'completed_at'], 'ebay_scrape_jobs_sweep_interval_index');
        });

        // A sweep belongs to no single card.
        Schema::table('ebay_scrape_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ebay_scrape_jobs', function (Blueprint $table) {
            $table->dropIndex('ebay_scrape_jobs_sweep_interval_index');
            $table->dropColumn(['kind', 'label']);
        });
    }
};
