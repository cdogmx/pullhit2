<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards people here are selling to each other.
 *
 * Named `marketplace_listings`, not `listings`, because `listing_observations`
 * already means something else entirely — the hundred thousand active eBay asks
 * we scrape to price cards. One is our users selling; the other is the market
 * being watched. A `Listing` model beside a `ListingObservation` model is one
 * careless import away from a bad afternoon.
 *
 * CardFoo is a venue. Nothing here holds money or records how it moved: payment
 * is arranged between the two people, in chat, or through Trustap as merchant
 * of record. There is deliberately nowhere to put a payment handle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('category', 16);
            $table->string('title');
            $table->text('description')->nullable();

            // The catalogued card this is a copy of, when the seller picked one.
            // Named catalog_item_id like every other table that points here —
            // sale_observations, market_values, wishlist_items all use it, and a
            // lone `card_id` for the same target costs somebody an hour later.
            // Nullable: a lot or an obscure card may match nothing we hold.
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();

            // Kept alongside the FK so an unlinked listing is still searchable,
            // and so the listing still reads correctly if the catalog row moves.
            $table->string('set_code', 32)->nullable();
            $table->string('card_number', 16)->nullable();

            // FK, not an enum: grading_companies is a real table that
            // market_values and sale_observations already join to, so a listing
            // and its comps line up, and adding a grader is not a migration.
            $table->foreignId('grading_company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('grade', 8)->nullable();
            $table->string('cert_number', 32)->nullable();

            // The Condition enum the valuation engine already speaks, so "listed
            // NM" and "NM comps" mean the same thing.
            $table->string('condition', 8)->nullable();

            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('USD');
            $table->boolean('accepts_offers')->default(true);

            // Two booleans rather than a SET column: MySQL has SET, SQLite has
            // no grammar for it at all, and the test suite runs on SQLite — the
            // migration would throw the moment anyone ran the tests. They also
            // index, which the browse filter needs.
            $table->boolean('accepts_direct')->default(true);
            $table->boolean('accepts_escrow')->default(true);

            $table->string('status', 16)->default('draft');
            $table->timestamp('bumped_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Browse: active listings of a category, bumped first.
            $table->index(['status', 'category', 'bumped_at']);
            // A cert offered by two different people is the loudest scam signal
            // this marketplace can produce; it has to be cheap to ask for.
            $table->index('cert_number');
            $table->index(['catalog_item_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('marketplace_listing_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_listing_id')->constrained()->cascadeOnDelete();
            // Our own bucket. We never hot-link a seller's image host: it can
            // change under us, and a listing whose photo 404s is worse than one
            // with no photo.
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            // Named, because the generated name would be 66 characters and
            // MySQL stops at 64.
            $table->index(['marketplace_listing_id', 'sort_order'], 'listing_photos_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listing_photos');
        Schema::dropIfExists('marketplace_listings');
    }
};
