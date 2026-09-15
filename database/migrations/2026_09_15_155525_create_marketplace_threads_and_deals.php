<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talking about a card, agreeing on a price, and saying afterwards how it went.
 *
 * All four tables carry the `marketplace_` prefix for the same reason the
 * listings table does: `messages`, `threads` and `feedback` are words this app
 * will want again for notifications, support and scan corrections, and a
 * `Message` model that turns out to mean only one of those is a trap.
 *
 * Nothing here records how money moved. A direct deal is two people saying it
 * happened; an escrow deal is Trustap saying so. CardFoo stores the claim and
 * the confirmation, never the payment — there is deliberately no column for a
 * handle, a card, or an amount transferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            // Read state per side, so an inbox can bold what the viewer has not
            // seen without walking every message in the thread.
            $table->timestamp('buyer_read_at')->nullable();
            $table->timestamp('seller_read_at')->nullable();
            $table->timestamps();

            // One conversation per buyer per listing: a second "Contact seller"
            // click returns to the thread rather than starting a parallel one
            // neither party can keep track of.
            $table->unique(['marketplace_listing_id', 'buyer_id'], 'thread_listing_buyer_unique');
            $table->index(['buyer_id', 'last_message_at']);
            $table->index(['seller_id', 'last_message_at']);
        });

        Schema::create('marketplace_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            // Free text and nothing else. Payment details are exchanged here, in
            // prose, on purpose: a structured "PayPal handle" field would make
            // CardFoo look like a participant in the payment rather than a venue.
            $table->text('body');
            $table->timestamps();

            $table->index(['marketplace_thread_id', 'id']);
        });

        Schema::create('marketplace_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_thread_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('seller_id')->constrained('users');

            $table->string('type', 12);    // direct | escrow
            $table->string('status', 12);  // proposed | accepted | paid | shipped | complete | disputed | cancelled

            $table->unsignedBigInteger('agreed_price_cents');
            $table->char('currency', 3)->default('USD');

            // Who proposed it, so the other side is the one who may accept.
            $table->foreignId('proposed_by_id')->constrained('users');

            $table->string('trustap_transaction_id')->nullable()->index();
            $table->string('tracking_number')->nullable();

            // A direct deal completes only when BOTH sides say it did. One
            // person's word is a claim; two is the closest this venue gets to
            // evidence without touching the money.
            $table->timestamp('buyer_confirmed_at')->nullable();
            $table->timestamp('seller_confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['marketplace_listing_id', 'status']);
            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);
        });

        Schema::create('marketplace_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ratee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 500)->nullable();
            // True only for escrow: an outside party confirmed the money and the
            // delivery. Direct feedback is two friends agreeing, which is worth
            // showing but not worth weighting the same.
            $table->boolean('verified')->default(false);
            $table->timestamps();

            // One rating per person per deal.
            $table->unique(['marketplace_deal_id', 'rater_id'], 'feedback_deal_rater_unique');
            $table->index(['ratee_id', 'verified']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Denormalised because they are read on every listing tile, profile
            // and thread — counting deals per render would be the most expensive
            // query on the busiest page.
            $table->unsignedInteger('direct_deal_count')->default(0)->after('username');
            $table->unsignedInteger('protected_deal_count')->default(0)->after('direct_deal_count');
            $table->decimal('avg_rating', 3, 2)->nullable()->after('protected_deal_count');
            $table->timestamp('identity_verified_at')->nullable()->after('avg_rating');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'direct_deal_count', 'protected_deal_count', 'avg_rating', 'identity_verified_at',
            ]);
        });

        Schema::dropIfExists('marketplace_feedback');
        Schema::dropIfExists('marketplace_deals');
        Schema::dropIfExists('marketplace_messages');
        Schema::dropIfExists('marketplace_threads');
    }
};
