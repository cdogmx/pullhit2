<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work for the browser agent: which sold searches to run, in what order, and
 * what came back.
 *
 * eBay now requires a signed-in session to read completed listings, so the
 * fetch has to happen in a real logged-in browser rather than from the server.
 * That inverts the usual arrangement — the worker is outside, unreliable, and
 * may vanish mid-job when a laptop sleeps — which is why jobs are *leased*
 * rather than handed over: an unfinished lease simply expires and the job
 * returns to the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_scrape_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->text('url');

            // pending → leased → done | failed | blocked
            // "blocked" is its own outcome: the agent reached eBay and was shown
            // a sign-in wall, which says nothing about the card and must not be
            // recorded as "this card has no comps".
            $table->string('status', 16)->default('pending');

            // Higher runs first. Lets a card someone is looking at now jump the
            // queue ahead of a routine refresh.
            $table->integer('priority')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('leased_until')->nullable();
            $table->string('leased_by', 64)->nullable();

            $table->unsignedSmallInteger('comps_found')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The claim query: pending (or expired lease) by priority, then age.
            $table->index(['status', 'priority', 'id'], 'ebay_scrape_jobs_claim_index');
            $table->index('leased_until');
            // One open job per card — enqueueing twice should not scrape twice.
            $table->index(['catalog_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_scrape_jobs');
    }
};
